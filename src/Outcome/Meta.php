<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Outcome;

use Swis\DecisionEngine\Support\Arr;

/**
 * Metadata about how an outcome was produced.
 *
 * `calibrated` is the flag that matters: Jev's and OpenAI Decisions' probabilities are calibrated, the LLM engines'
 * probabilities are self-reported. Downstream code that gates on confidence should check it.
 *
 * ```php
 * if (! $outcome->meta->calibrated) {
 *     // do not confidence-gate; treat the answer as a hint
 * }
 * $outcome->meta->requestId; // 'req_…' from x-typesafe-request-id
 * $outcome->meta->latencyMs; // 104.2
 * ```
 */
final class Meta
{
    /**
     * @param  array<string, mixed>  $extra  engine-specific extras (e.g. provider response id)
     */
    public function __construct(
        public readonly bool $calibrated,
        public readonly ?string $requestId = null,
        public readonly ?float $latencyMs = null,
        public readonly array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::bool($data, 'calibrated') ?? false,
            Arr::string($data, 'request_id'),
            Arr::float($data, 'latency_ms'),
            Arr::stringKeys(Arr::array($data, 'extra') ?? []),
        );
    }

    public function withLatency(float $latencyMs): self
    {
        return new self($this->calibrated, $this->requestId, $latencyMs, $this->extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = ['calibrated' => $this->calibrated];

        if ($this->requestId !== null) {
            $array['request_id'] = $this->requestId;
        }

        if ($this->latencyMs !== null) {
            $array['latency_ms'] = $this->latencyMs;
        }

        if ($this->extra !== []) {
            $array['extra'] = $this->extra;
        }

        return $array;
    }
}
