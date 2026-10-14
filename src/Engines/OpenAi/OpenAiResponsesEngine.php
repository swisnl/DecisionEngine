<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\OpenAi;

use Swis\DecisionEngine\Engines\Llm\StructuredOutputEngine;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Outcome\Usage;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * OpenAI Responses API (`POST /v1/responses`) with a strict `json_schema` text format: a general
 * model self-reports the probabilities. Default model `gpt-5.6-luna`, `reasoning.effort: none` for
 * latency, `store: false`. For OpenAI's dedicated Decisions API use {@see OpenAiEngine}.
 *
 * Engine options passed through to the body: `reasoning`, `temperature`, `top_p`,
 * `max_output_tokens`, `store`, `metadata`, `service_tier`, `prompt_cache_key`; plus `merge_body`.
 *
 * ```php
 * Decision::for($state)->using('luna-responses')->engineOptions(['reasoning' => ['effort' => 'low']])->…
 * ```
 */
final class OpenAiResponsesEngine extends StructuredOutputEngine
{
    public const NAME = 'openai_responses';

    public const DEFAULT_BASE_URL = 'https://api.openai.com';

    public const DEFAULT_MODEL = 'gpt-5.6-luna';

    private const PASSTHROUGH = ['reasoning', 'temperature', 'top_p', 'max_output_tokens', 'store', 'metadata', 'service_tier', 'prompt_cache_key'];

    /**
     * @param  array<string, mixed>  $config  `{api_key, base_url?, model?, options?, organization?, project?}`
     */
    public static function fromConfig(array $config, ?Thresholds $thresholds = null): self
    {
        $apiKey = $config['api_key'] ?? null;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw ConfigurationException::missing('engines.' . self::NAME . '.api_key');
        }

        $options = Arr::stringKeys(Arr::array($config, 'options') ?? []);

        foreach (['organization', 'project'] as $key) {
            if (is_string($config[$key] ?? null)) {
                $options[$key] = $config[$key];
            }
        }

        return new self($apiKey, Arr::string($config, 'base_url') ?? self::DEFAULT_BASE_URL, Arr::string($config, 'model') ?? self::DEFAULT_MODEL, $options, $thresholds ?? new Thresholds());
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function supportsNumericConstraints(): bool
    {
        return true;
    }

    protected function endpoint(): string
    {
        return '/v1/responses';
    }

    protected function headers(): array
    {
        return OpenAiEngine::authHeaders($this->apiKey, $this->options);
    }

    protected function body(string $model, string $system, string $user, array $schema, array $options, int $questionCount): array
    {
        $body = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'decision',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            'reasoning' => ['effort' => 'none'],
            'store' => false,
        ];

        return self::passthrough($body, $options, self::PASSTHROUGH);
    }

    protected function extractOutput(array $body, HttpResponse $response): array
    {
        $status = $body['status'] ?? 'completed';

        if ($status === 'incomplete') {
            $details = $body['incomplete_details'] ?? [];
            $reason = is_array($details) && is_string($details['reason'] ?? null) ? $details['reason'] : 'unknown';

            throw ResponseValidationException::at('status', "response is incomplete ({$reason}); raise max_output_tokens or simplify the questions", $response, null, self::NAME);
        }

        $output = $body['output'] ?? null;

        if (! is_array($output)) {
            throw ResponseValidationException::at('output', 'expected a list of output items', $response, null, self::NAME);
        }

        foreach ($output as $i => $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? [];

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $j => $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (($part['type'] ?? null) === 'refusal') {
                    $refusal = is_string($part['refusal'] ?? null) ? $part['refusal'] : 'no reason given';

                    throw ResponseValidationException::at("output.{$i}.content.{$j}", "the model refused: {$refusal}", $response, null, self::NAME);
                }

                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    return $this->decodeOutput($part['text'], "output.{$i}.content.{$j}.text", $response);
                }
            }
        }

        throw ResponseValidationException::at('output', 'no output_text message found', $response, null, self::NAME);
    }

    protected function extractUsage(array $body): Usage
    {
        $usage = $body['usage'] ?? [];

        return is_array($usage) ? Usage::fromArray(Arr::stringKeys($usage)) : Usage::zero();
    }

    protected function extractMeta(array $body, HttpResponse $response): array
    {
        return Arr::withoutNulls([
            'response_id' => is_string($body['id'] ?? null) ? $body['id'] : null,
            'status' => is_string($body['status'] ?? null) ? $body['status'] : null,
        ]);
    }

    protected function requestIdHeader(): string
    {
        return 'x-request-id';
    }
}
