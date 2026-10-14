<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\Jev;

use Swis\DecisionEngine\Answers\AnswerFactory;
use Swis\DecisionEngine\Answers\MalformedAnswerException;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Engines\Capabilities;
use Swis\DecisionEngine\Exceptions\ApiException;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Outcome\Meta;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Outcome\Usage;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Version;

/**
 * TypeSafe's System One API (`POST /v1/systemone`). The default engine; calibrated.
 *
 * The wire body is always `{state, model, questions}` plus whatever `mergePayload()` /
 * `engineOptions(['merge_body' => …])` added.
 *
 * ```php
 * $engine = JevEngine::fromConfig(['api_key' => getenv('TYPESAFE_API_KEY')]);
 * $engine->prepare($request)->json(); // ['state' => …, 'model' => 'jev-latest', 'questions' => […]]
 * ```
 */
final class JevEngine implements Engine
{
    public const NAME = 'jev';

    public const DEFAULT_BASE_URL = 'https://api.typesafe.ai';

    public const DEFAULT_MODEL = 'jev-latest';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly Thresholds $thresholds = new Thresholds(),
        private readonly Capabilities $capabilities = new Capabilities(supportsNullState: false),
    ) {
        if (trim($apiKey) === '') {
            throw ConfigurationException::missing('engines.jev.api_key');
        }
    }

    /**
     * @param  array<string, mixed>  $config  `{api_key, base_url?, model?}`
     */
    public static function fromConfig(array $config, ?Thresholds $thresholds = null): self
    {
        $apiKey = $config['api_key'] ?? null;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw ConfigurationException::missing('engines.jev.api_key');
        }

        return new self(
            $apiKey,
            Arr::string($config, 'base_url') ?? self::DEFAULT_BASE_URL,
            Arr::string($config, 'model') ?? self::DEFAULT_MODEL,
            $thresholds ?? new Thresholds(),
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function models(): ModelsEndpoint
    {
        return new ModelsEndpoint($this->apiKey, $this->baseUrl);
    }

    public function prepare(DecisionRequest $request): PreparedRequest
    {
        $payload = $request->payload();

        $body = [
            'state' => $payload['state'] ?? null,
            'model' => $payload['model'] ?? $this->model,
            'questions' => $payload['questions'] ?? [],
        ];

        $body = array_replace($body, Arr::except($payload, ['state', 'model', 'questions']));

        $mergeBody = $request->engineOptions['merge_body'] ?? null;

        if (is_array($mergeBody)) {
            $body = array_replace($body, Arr::stringKeys($mergeBody));
        }

        $model = $body['model'];

        return PreparedRequest::jsonRequest(
            'POST',
            rtrim($this->baseUrl, '/') . '/v1/systemone',
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'User-Agent' => Version::userAgent(),
            ],
            $body,
            [
                'engine' => self::NAME,
                'model' => is_string($model) ? $model : $this->model,
                'questions' => $request->questionIds(),
            ],
        );
    }

    public function interpret(DecisionRequest $request, HttpResponse $response): Outcome
    {
        if (! $response->isSuccess()) {
            throw ApiException::fromResponse($response, null, self::NAME);
        }

        try {
            $body = $response->json();
        } catch (\JsonException $e) {
            throw ResponseValidationException::at('', 'body is not valid JSON', $response, null, self::NAME, $e);
        }

        $model = $body['model'] ?? null;

        if (! is_string($model)) {
            throw ResponseValidationException::at('model', 'expected a string', $response, null, self::NAME);
        }

        $answers = $body['answers'] ?? null;

        if (! is_array($answers)) {
            throw ResponseValidationException::at('answers', 'expected an object of id → answer', $response, null, self::NAME);
        }

        $usage = $body['usage'] ?? [];

        if (! is_array($usage)) {
            throw ResponseValidationException::at('usage', 'expected an object', $response, null, self::NAME);
        }

        foreach (['input_tokens', 'output_tokens'] as $key) {
            if (array_key_exists($key, $usage) && ! is_int($usage[$key])) {
                throw ResponseValidationException::at("usage.{$key}", 'expected an integer', $response, null, self::NAME);
            }
        }

        try {
            $parsed = AnswerFactory::fromAnswers($answers, $this->thresholds);
        } catch (MalformedAnswerException $e) {
            throw ResponseValidationException::at($e->fieldPath, $e->getMessage(), $response, null, self::NAME, $e);
        }

        return new Outcome(
            answers: $parsed,
            model: $model,
            engine: self::NAME,
            usage: Usage::fromArray(Arr::stringKeys($usage)),
            meta: new Meta(
                calibrated: $this->capabilities->calibrated,
                requestId: $response->header('x-typesafe-request-id'),
                latencyMs: $response->latencyMs,
            ),
            raw: $body,
            response: $response,
        );
    }
}
