<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel\Collections;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;
use Swis\DecisionEngine\Concurrency\BatchDecision;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Laravel\ProvidesDecisionState;
use Swis\DecisionEngine\Outcome\Outcome;

/**
 * Decides the same questions for every item of a collection and hands each item back with its
 * outcome. Backs the collection macros registered by `CollectionMacros`.
 *
 * Items are decided concurrently in one batch; a `LazyCollection` is decided per chunk of
 * `LAZY_CHUNK` items so it streams.
 *
 * State per item, in order: the `$state` closure `($item, $key)`; an attribute name or list of
 * names read with `data_get()`; `ProvidesDecisionState::toDecisionState()`; the item itself. An
 * Eloquent model without any of these is rejected rather than sending every column.
 *
 * `$settle`: `false` throws `ParallelFailedException` when any item fails; `true` leaves failed items
 * out of the result; a closure `(\Throwable, $item, $key)` is called for each failed item, which is
 * then left out.
 *
 * @internal
 */
final class CollectionDecider
{
    public const int LAZY_CHUNK = 100;

    /** @var \Closure(mixed, array-key): mixed|string|list<string>|null */
    private readonly \Closure|string|array|null $state;

    /**
     * @param  \Closure(BatchDecision): mixed  $questions
     * @param  mixed  $state  `(\Closure(mixed, array-key): mixed)|string|list<string>|null`
     * @param  bool|(\Closure(\Throwable, mixed, array-key): mixed)  $settle
     */
    public function __construct(
        private readonly \Closure $questions,
        mixed $state = null,
        private readonly string|Engine|null $using = null,
        private readonly ?string $model = null,
        private readonly ?int $concurrency = null,
        private readonly bool|\Closure $settle = false,
    ) {
        $this->state = match (true) {
            $state === null, $state instanceof \Closure, is_string($state) => $state,
            is_array($state) && array_is_list($state) && array_filter($state, is_string(...)) === $state => $state,
            default => throw new \InvalidArgumentException('`state:` must be a closure, an attribute name or a list of attribute names.'),
        };
    }

    /**
     * Every decided item as `[key, item, outcome]`; failed items are thrown or skipped per `$settle`.
     *
     * @param  Enumerable<array-key, mixed>  $items
     * @return \Generator<int, array{array-key, mixed, Outcome}>
     */
    public function decided(Enumerable $items): \Generator
    {
        $chunks = $items instanceof LazyCollection ? $items->chunk(self::LAZY_CHUNK) : [$items];

        foreach ($chunks as $chunk) {
            /** @var array<array-key, mixed> $chunk */
            $chunk = $chunk->all();
            $batch = $this->batch($chunk);

            $results = $this->settle === false ? $batch->decide($this->concurrency) : $batch->settle($this->concurrency);

            foreach ($chunk as $key => $item) {
                $result = $results[$key];

                if ($result instanceof \Throwable) {
                    if ($this->settle instanceof \Closure) {
                        ($this->settle)($result, $item, $key);
                    }

                    continue;
                }

                yield [$key, $item, $result];
            }
        }
    }

    /**
     * Items whose outcome passes `$predicate`, keys preserved; streams for a LazyCollection.
     *
     * @template TCollection of Enumerable<array-key, mixed>
     *
     * @param  TCollection  $items
     * @param  \Closure(Outcome, mixed, array-key): bool  $predicate
     * @return TCollection
     */
    public function filter(Enumerable $items, \Closure $predicate): Enumerable
    {
        return $this->collect($items, function () use ($items, $predicate): \Generator {
            foreach ($this->decided($items) as [$key, $item, $outcome]) {
                if ($predicate($outcome, $item, $key)) {
                    yield $key => $item;
                }
            }
        });
    }

    /**
     * The outcome per item, keys preserved; streams for a LazyCollection.
     *
     * @param  Enumerable<array-key, mixed>  $items
     * @return Enumerable<array-key, Outcome>
     */
    public function outcomes(Enumerable $items): Enumerable
    {
        /** @var Enumerable<array-key, Outcome> $outcomes */
        $outcomes = $this->collect($items, function () use ($items): \Generator {
            foreach ($this->decided($items) as [$key, , $outcome]) {
                yield $key => $outcome;
            }
        });

        return $outcomes;
    }

    /**
     * Hand the decided items and a `($item, $key) => value` lookup to one of the collection's own
     * methods (`sortBy`, `groupBy`, `partition`). Deferred until enumeration for a LazyCollection.
     *
     * @template TValue
     * @template TResult
     *
     * @param  Enumerable<array-key, mixed>  $items
     * @param  \Closure(Outcome): TValue  $value
     * @param  \Closure(Enumerable<array-key, mixed>, \Closure(mixed, array-key): TValue): TResult  $apply
     * @return TResult|LazyCollection<array-key, mixed>
     */
    public function by(Enumerable $items, \Closure $value, \Closure $apply): mixed
    {
        $run = function () use ($items, $value, $apply): mixed {
            $kept = [];
            $values = [];

            foreach ($this->decided($items) as [$key, $item, $outcome]) {
                $kept[$key] = $item;
                $values[$key] = $value($outcome);
            }

            return $apply($items::make($kept), static fn(mixed $item, int|string $key) => $values[$key]);
        };

        if ($items instanceof LazyCollection) {
            return $this->collect($items, static function () use ($run): \Generator {
                $result = $run();

                if (is_iterable($result)) {
                    yield from $result;
                }
            });
        }

        return $run();
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    private function batch(array $items): BatchDecision
    {
        if ($this->state === null) {
            foreach ($items as $item) {
                if ($item instanceof Model && ! $item instanceof ProvidesDecisionState) {
                    throw new \InvalidArgumentException('Pass `state:` (a closure or attribute names) or implement ' . ProvidesDecisionState::class . ' on ' . $item::class . ' to choose which attributes are sent; refusing to send every column.');
                }
            }
        }

        $batch = Decision::forEach($items, fn(mixed $item, int|string $key): mixed => $this->stateFor($item, $key));

        if ($this->using !== null) {
            $batch->using($this->using);
        }

        if ($this->model !== null) {
            $batch->model($this->model);
        }

        ($this->questions)($batch);

        return $batch;
    }

    private function stateFor(mixed $item, int|string $key): mixed
    {
        if ($this->state instanceof \Closure) {
            return ($this->state)($item, $key);
        }

        if (is_string($this->state)) {
            return data_get($item, $this->state);
        }

        if (is_array($this->state)) {
            $state = [];

            foreach ($this->state as $attribute) {
                $state[$attribute] = data_get($item, $attribute);
            }

            return $state;
        }

        if ($item instanceof ProvidesDecisionState) {
            return $item->toDecisionState();
        }

        return $item;
    }

    /**
     * @template TCollection of Enumerable<array-key, mixed>
     *
     * @param  TCollection  $items
     * @param  \Closure(): \Generator<array-key, mixed, mixed, void>  $generator
     * @return TCollection
     */
    private function collect(Enumerable $items, \Closure $generator): Enumerable
    {
        /** @var TCollection $result */
        $result = $items instanceof LazyCollection ? LazyCollection::make($generator) : $items::make(iterator_to_array($generator()));

        return $result;
    }
}
