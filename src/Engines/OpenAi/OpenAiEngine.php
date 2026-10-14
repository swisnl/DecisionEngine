<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\OpenAi;

use Swis\DecisionEngine\Answers\AnswerFactory;
use Swis\DecisionEngine\Answers\MalformedAnswerException;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Engines\Capabilities;
use Swis\DecisionEngine\Exceptions\ApiException;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Outcome\Meta;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Outcome\Usage;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Serialization\QuestionFactory;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Support\Math;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Version;

/**
 * OpenAI Decisions API (`POST /v1/decisions`, public beta). A dedicated endpoint for System One
 * work: the model returns probability distributions directly instead of filling a JSON schema.
 * Default (and currently only) model `gpt-6-luna`.
 *
 * Questions map 1:1: `choice` → `choice` (`choices: [{value, description}]`), `score` → `score`
 * (`levels: [{label, description}]`, 0-indexed like Jev) and `noul` → `predicate`. Structured
 * descriptions (`what / not_for / examples / signals`) and noul `true` / `false` criteria are
 * rendered into the plain-text `description` / `instructions` the API expects; a null description
 * is omitted and null instructions are sent as `""` (the API requires the field). The state is
 * sent as `input` (JSON-encoded when it is not a string). Like Jev, the API accepts 2–10 score
 * levels and up to 255 choices, and the probabilities are treated as calibrated.
 *
 * Engine options: `merge_body` (merged into the request body).
 *
 * ```php
 * Decision::for($state)->using('luna')->choice('department', 'Which team?', [...])->decide();
 * ```
 */
final class OpenAiEngine implements Engine
{
    public const NAME = 'openai';

    public const DEFAULT_BASE_URL = 'https://api.openai.com';

    public const DEFAULT_MODEL = 'gpt-6-luna';

    /**
     * @param  array<string, mixed>  $options  engine-level defaults (`organization`, `project`, `merge_body`), overridden per request by `engineOptions()`
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly array $options = [],
        private readonly Thresholds $thresholds = new Thresholds(),
    ) {
        if (trim($apiKey) === '') {
            throw ConfigurationException::missing('engines.' . self::NAME . '.api_key');
        }
    }

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

    /**
     * Authentication headers shared by the OpenAI engines.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    public static function authHeaders(string $apiKey, array $options): array
    {
        $headers = ['Authorization' => 'Bearer ' . $apiKey];

        if (is_string($options['organization'] ?? null)) {
            $headers['OpenAI-Organization'] = $options['organization'];
        }

        if (is_string($options['project'] ?? null)) {
            $headers['OpenAI-Project'] = $options['project'];
        }

        return $headers;
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
        return new Capabilities(supportsNullState: false);
    }

    public function prepare(DecisionRequest $request): PreparedRequest
    {
        $payload = $request->payload();
        $questions = $this->effectiveQuestions($request);
        $model = $payload['model'] ?? $this->model;
        $model = is_string($model) ? $model : $this->model;
        $state = $payload['state'] ?? null;

        $wire = [];

        foreach ($questions as $id => $question) {
            $wire[] = self::question($id, self::resolve($id, $question));
        }

        $body = [
            'model' => $model,
            'input' => is_string($state) ? $state : Json::encode($state, pretty: true),
            'questions' => $wire,
        ];

        $mergeBody = Arr::replaceRecursive($this->options, $request->engineOptions)['merge_body'] ?? null;

        if (is_array($mergeBody)) {
            $body = Arr::replaceRecursive($body, Arr::stringKeys($mergeBody));
        }

        return PreparedRequest::jsonRequest(
            'POST',
            rtrim($this->baseUrl, '/') . '/v1/decisions',
            ['User-Agent' => Version::userAgent()] + self::authHeaders($this->apiKey, $this->options),
            $body,
            ['engine' => self::NAME, 'model' => $model, 'questions' => $questions->ids()],
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

        $list = $body['answers'] ?? null;

        if (! is_array($list) || ! array_is_list($list)) {
            throw ResponseValidationException::at('answers', 'expected a list of answers', $response, null, self::NAME);
        }

        $byName = [];

        foreach ($list as $i => $answer) {
            if (! is_array($answer) || ! is_string($answer['name'] ?? null)) {
                throw ResponseValidationException::at("answers.{$i}.name", 'expected a string', $response, null, self::NAME);
            }

            if (($answer['type'] ?? null) === 'refusal') {
                throw ResponseValidationException::at("answers.{$i}", "the model refused to answer [{$answer['name']}]", $response, null, self::NAME);
            }

            $byName[$answer['name']] = $answer;
        }

        try {
            $answers = [];

            foreach ($this->effectiveQuestions($request) as $id => $question) {
                $raw = $byName[$id] ?? throw MalformedAnswerException::required("answers.{$id}");
                $answers[$id] = self::answer($id, self::resolve($id, $question), Arr::stringKeys($raw));
            }

            $parsed = AnswerFactory::fromAnswers($answers, $this->thresholds);
        } catch (MalformedAnswerException $e) {
            throw ResponseValidationException::at($e->fieldPath, $e->getMessage(), $response, null, self::NAME, $e);
        }

        $model = $body['model'] ?? null;
        $usage = $body['usage'] ?? null;

        return new Outcome(
            answers: $parsed,
            model: is_string($model) ? $model : ($request->model ?? $this->model),
            engine: self::NAME,
            usage: is_array($usage) ? Usage::fromArray(Arr::stringKeys($usage)) : Usage::zero(),
            meta: new Meta(
                calibrated: true,
                requestId: $response->header('x-request-id'),
                latencyMs: $response->latencyMs,
            ),
            raw: $body,
            response: $response,
        );
    }

    /**
     * Questions as sent (payload mutators applied).
     */
    private function effectiveQuestions(DecisionRequest $request): QuestionSet
    {
        if (! $request->hasMutators()) {
            return $request->questions;
        }

        $questions = $request->payload()['questions'] ?? null;

        return is_array($questions) ? QuestionSet::fromArray($questions) : $request->questions;
    }

    /**
     * Raw questions of a known type are upgraded to their typed class; unknown types cannot be sent.
     */
    private static function resolve(string $id, Question $question): Question
    {
        if (! $question instanceof RawQuestion) {
            return $question;
        }

        if (! $question->isKnownType()) {
            throw InvalidDecisionException::single($id, 'The OpenAI Decisions API only supports the choice, score and noul question types; got [' . ($question->rawType() ?? 'null') . '].');
        }

        return QuestionFactory::fromArray($id, $question->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private static function question(string $id, Question $question): array
    {
        if ($question instanceof Choice) {
            $choices = [];

            foreach ($question->criteria() as $value => $description) {
                $choices[] = Arr::withoutNulls(['value' => (string) $value, 'description' => self::text($description)]);
            }

            return ['type' => 'choice', 'name' => $id, 'instructions' => self::text($question->instructions()) ?? '', 'choices' => $choices];
        }

        if ($question instanceof Score) {
            $levels = [];

            foreach ($question->criteria() as $index => $description) {
                $levels[] = Arr::withoutNulls(['label' => (string) $index, 'description' => self::text($description)]);
            }

            return ['type' => 'score', 'name' => $id, 'instructions' => self::text($question->instructions()) ?? '', 'levels' => $levels];
        }

        if ($question instanceof Noul) {
            $instructions = self::text($question->instructions());
            $criteria = $question->getCriteria() ?? [];

            foreach (['true' => 'True when', 'false' => 'False when'] as $key => $label) {
                $description = self::text($criteria[$key] ?? null);

                if ($description !== null) {
                    $instructions = ($instructions === null ? '' : $instructions . "\n") . "{$label}: {$description}";
                }
            }

            return ['type' => 'predicate', 'name' => $id, 'instructions' => $instructions ?? ''];
        }

        throw InvalidDecisionException::single($id, 'Unsupported question class ' . $question::class . ' for the OpenAI Decisions API.');
    }

    /**
     * Convert a Decisions API answer into the Jev answer shape.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function answer(string $id, Question $question, array $raw): array
    {
        $type = $raw['type'] ?? null;
        $expected = match (true) {
            $question instanceof Choice => 'choice',
            $question instanceof Score => 'score',
            default => 'predicate',
        };

        if ($type !== $expected) {
            throw MalformedAnswerException::type("answers.{$id}.type", "\"{$expected}\"");
        }

        if ($question instanceof Noul) {
            return ['type' => 'noul', 'noul' => self::number("answers.{$id}.probability", $raw['probability'] ?? null)];
        }

        $probabilities = [];

        foreach (is_array($raw['probabilities'] ?? null) ? $raw['probabilities'] : throw MalformedAnswerException::required("answers.{$id}.probabilities") as $i => $entry) {
            $value = is_array($entry) ? ($entry['value'] ?? null) : null;

            if (! is_string($value) && ! is_int($value)) {
                throw MalformedAnswerException::type("answers.{$id}.probabilities.{$i}.value", 'a string or integer');
            }

            $probabilities[(string) $value] = self::number("answers.{$id}.probabilities.{$i}.probability", $entry['probability'] ?? null);
        }

        /** @var array<string, float> $probabilities */
        $confidence = isset($raw['confidence']) ? self::number("answers.{$id}.confidence", $raw['confidence']) : ($probabilities === [] ? 0.0 : Math::confidenceOf($probabilities));

        if ($question instanceof Choice) {
            return [
                'type' => 'choice',
                'choice' => is_string($raw['choice'] ?? null) ? $raw['choice'] : throw MalformedAnswerException::type("answers.{$id}.choice", 'a string'),
                'confidence' => $confidence,
                'probabilities' => $probabilities,
            ];
        }

        $legend = [];

        foreach ($question instanceof Score ? $question->criteria() : [] as $index => $description) {
            $legend[(string) $index] = $description;
        }

        return [
            'type' => 'score',
            'score' => self::number("answers.{$id}.score", $raw['score'] ?? null),
            'confidence' => $confidence,
            'legend' => $legend,
            'probabilities' => $probabilities,
        ];
    }

    private static function number(string $path, mixed $value): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw MalformedAnswerException::type($path, 'a number');
        }

        return (float) $value;
    }

    /**
     * Render a description (`string | {what, not_for, examples, signals} | any array | null`) as plain text.
     */
    private static function text(mixed $description): ?string
    {
        if ($description === null || is_string($description)) {
            return $description;
        }

        if (! is_array($description)) {
            return Json::encode($description);
        }

        $structured = ['what' => '%s', 'not_for' => 'Not for: %s', 'examples' => 'Examples: %s', 'signals' => 'Signals: %s'];

        if ($description === [] || array_diff_key($description, $structured) !== []) {
            return Json::encode($description);
        }

        $lines = [];

        foreach ($structured as $key => $format) {
            $value = $description[$key] ?? null;

            if (is_array($value)) {
                $value = implode('; ', array_map(fn(mixed $v): string => is_string($v) ? $v : Json::encode($v), $value));
            }

            if ($value !== null) {
                $lines[] = sprintf($format, is_string($value) ? $value : Json::encode($value));
            }
        }

        return implode("\n", $lines);
    }
}
