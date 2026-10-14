<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;
use Swis\DecisionEngine\Concurrency\BatchDecision;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\Score;

/**
 * Collection macros that decide about every item: filter, sort, group and partition on the answer.
 * Registered on `Collection` and `LazyCollection` (and so on Eloquent collections). Each item is one
 * decision; items are decided concurrently, a LazyCollection per chunk.
 *
 * ```php
 * $tickets->whereNoul('is_spam', 'Is this message spam?', threshold: 0.9, state: 'body');
 * $tickets->whereChoice('department', 'Which team?', ['billing' => null, 'returns' => null, 'other' => null], is: 'billing');
 * $tickets->whereScore('severity', 'How severe?', $levels, '>=', 1.5, state: ['subject', 'body']);
 * $results->sortByScoreDesc('relevance', "How relevant is this to: {$query}?", $levels, state: 'summary');
 * $tickets->groupByChoice('department', 'Which team?', $options, state: 'body');
 * [$spam, $ham] = $tickets->partitionByNoul('is_spam', 'Is this spam?', state: 'body');
 *
 * $tickets->whereDecision(
 *     fn (BatchDecision $d) => $d->noul('urgent', 'Is this urgent?')->score('severity', 'How severe?', $levels),
 *     fn (Outcome $o) => $o->urgent->isTrue(0.8) && $o->severity->atLeast(1.5),
 *     state: fn (Ticket $t) => ['message' => $t->body],
 * );
 * $outcomes = $tickets->decide(fn (BatchDecision $d) => $d->noul('urgent', 'Is this urgent?'), state: 'body');
 * ```
 *
 * A question is an id plus instructions (and options / levels), or a question object
 * (`Noul::make(...)->criteria(...)`), in which case the other question arguments are ignored.
 *
 * Every macro accepts the named arguments `state` (closure `($item, $key)`, attribute name or list
 * of names; see `CollectionDecider`), `using`, `model`, `concurrency` and `settle` (`true` leaves
 * failed items out, a closure `(\Throwable, $item, $key)` is also told about them; the default
 * throws `ParallelFailedException`).
 */
final class CollectionMacros
{
    // Helpers are public: Laravel binds macros to the collection's scope, so they cannot reach private members.
    public const array OPERATORS = ['>=', '>', '<=', '<', '=', '==', '!=', '<>'];

    public static function register(): void
    {
        foreach ((new self())->macros() as $name => $macro) {
            Collection::macro($name, $macro);
            LazyCollection::macro($name, $macro);
        }
    }

    /**
     * Instance method: macro closures must not be static, Laravel binds them to the collection.
     *
     * @return array<string, \Closure>
     */
    private function macros(): array
    {
        return [
            /**
             * The outcome per item, keys preserved.
             *
             * @param  \Closure(BatchDecision): mixed  $questions
             */
            'decide' => function (\Closure $questions, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                return (new CollectionDecider($questions, $state, $using, $model, $concurrency, $settle))->outcomes(CollectionMacros::self($this));
            },

            /**
             * Items whose outcome passes `$predicate`.
             *
             * @param  \Closure(BatchDecision): mixed  $questions
             * @param  \Closure(Outcome, mixed, array-key): bool  $predicate
             */
            'whereDecision' => function (\Closure $questions, \Closure $predicate, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                return (new CollectionDecider($questions, $state, $using, $model, $concurrency, $settle))->filter(CollectionMacros::self($this), $predicate);
            },

            /** Items whose noul is at least `$threshold`. */
            'whereNoul' => function (string|Noul $question, mixed $instructions = null, float $threshold = 0.5, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::noul($question, $instructions);

                return (new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle))
                    ->filter(CollectionMacros::self($this), static fn(Outcome $outcome): bool => $outcome->noul($question->id())->isTrue($threshold));
            },

            /** Items whose noul is below `$threshold`. */
            'whereNotNoul' => function (string|Noul $question, mixed $instructions = null, float $threshold = 0.5, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::noul($question, $instructions);

                return (new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle))
                    ->filter(CollectionMacros::self($this), static fn(Outcome $outcome): bool => ! $outcome->noul($question->id())->isTrue($threshold));
            },

            /**
             * Items whose chosen option is one of `$is`, with at least `$minConfidence`.
             *
             * @param  array<array-key, mixed>|null  $options
             * @param  string|\BackedEnum|list<string|\BackedEnum>  $is
             */
            'whereChoice' => function (string|Choice $question, mixed $instructions = null, ?array $options = null, string|\BackedEnum|array $is = [], float $minConfidence = 0.0, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::choice($question, $instructions, $options);
                $accepted = [];

                foreach (is_array($is) ? $is : [$is] as $option) {
                    $accepted[] = match (true) {
                        $option instanceof \BackedEnum => (string) $option->value,
                        is_string($option) => $option,
                        default => throw new \InvalidArgumentException('`is:` takes option names or backed enum cases.'),
                    };
                }

                if ($accepted === []) {
                    throw new \InvalidArgumentException("whereChoice('{$question->id()}') needs the accepted option(s) as `is:`.");
                }

                return (new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle))
                    ->filter(CollectionMacros::self($this), static function (Outcome $outcome) use ($question, $accepted, $minConfidence): bool {
                        $answer = $outcome->choice($question->id());

                        return in_array($answer->choice, $accepted, true) && $answer->confidence >= $minConfidence;
                    });
            },

            /**
             * Items whose score compares to `$value` with `$operator` (`>=`, `>`, `<=`, `<`, `=`, `!=`).
             *
             * @param  list<mixed>|null  $levels
             */
            'whereScore' => function (string|Score $question, mixed $instructions = null, ?array $levels = null, string $operator = '>=', ?float $value = null, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::score($question, $instructions, $levels);

                if ($value === null || ! in_array($operator, CollectionMacros::OPERATORS, true)) {
                    throw new \InvalidArgumentException("whereScore('{$question->id()}') needs an operator (" . implode(', ', CollectionMacros::OPERATORS) . ') and a value.');
                }

                return (new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle))
                    ->filter(CollectionMacros::self($this), static fn(Outcome $outcome): bool => CollectionMacros::compare($outcome->score($question->id())->score, $operator, $value));
            },

            /** @param  list<mixed>|null  $levels */
            'sortByScore' => function (string|Score $question, mixed $instructions = null, ?array $levels = null, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::score($question, $instructions, $levels);

                return CollectionMacros::sortBy(CollectionMacros::self($this), new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle), static fn(Outcome $outcome): float => $outcome->score($question->id())->score, false);
            },

            /** @param  list<mixed>|null  $levels */
            'sortByScoreDesc' => function (string|Score $question, mixed $instructions = null, ?array $levels = null, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::score($question, $instructions, $levels);

                return CollectionMacros::sortBy(CollectionMacros::self($this), new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle), static fn(Outcome $outcome): float => $outcome->score($question->id())->score, true);
            },

            'sortByNoul' => function (string|Noul $question, mixed $instructions = null, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::noul($question, $instructions);

                return CollectionMacros::sortBy(CollectionMacros::self($this), new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle), static fn(Outcome $outcome): float => $outcome->noul($question->id())->noul, false);
            },

            'sortByNoulDesc' => function (string|Noul $question, mixed $instructions = null, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::noul($question, $instructions);

                return CollectionMacros::sortBy(CollectionMacros::self($this), new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle), static fn(Outcome $outcome): float => $outcome->noul($question->id())->noul, true);
            },

            /**
             * Items grouped by their chosen option.
             *
             * @param  array<array-key, mixed>|null  $options
             */
            'groupByChoice' => function (string|Choice $question, mixed $instructions = null, ?array $options = null, bool $preserveKeys = false, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::choice($question, $instructions, $options);

                /** @var Enumerable<string, Enumerable<array-key, mixed>> $groups */
                $groups = (new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle))->by(
                    CollectionMacros::self($this),
                    static fn(Outcome $outcome): string => $outcome->choice($question->id())->choice,
                    static fn(Enumerable $items, \Closure $value): Enumerable => $items->groupBy($value, $preserveKeys),
                );

                return $groups;
            },

            /** `[items whose noul is at least $threshold, the rest]`, keys preserved. */
            'partitionByNoul' => function (string|Noul $question, mixed $instructions = null, float $threshold = 0.5, mixed $state = null, string|Engine|null $using = null, ?string $model = null, ?int $concurrency = null, bool|\Closure $settle = false): Enumerable {
                $question = CollectionMacros::noul($question, $instructions);
                $items = CollectionMacros::self($this);
                $decider = new CollectionDecider(CollectionMacros::asks($question), $state, $using, $model, $concurrency, $settle);

                $passed = [];
                $failed = [];

                foreach ($decider->decided($items) as [$key, $item, $outcome]) {
                    if ($outcome->noul($question->id())->isTrue($threshold)) {
                        $passed[$key] = $item;
                    } else {
                        $failed[$key] = $item;
                    }
                }

                return $items::make([$items::make($passed), $items::make($failed)]);
            },
        ];
    }

    /**
     * @param  Enumerable<array-key, mixed>  $items
     * @param  \Closure(Outcome): float  $value
     * @return Enumerable<array-key, mixed>
     */
    public static function sortBy(Enumerable $items, CollectionDecider $decider, \Closure $value, bool $descending): Enumerable
    {
        /** @var Enumerable<array-key, mixed> $sorted */
        $sorted = $decider->by(
            $items,
            $value,
            static fn(Enumerable $kept, \Closure $by): Enumerable => $kept->sortBy($by, SORT_REGULAR, $descending),
        );

        return $sorted;
    }

    /**
     * The collection a macro is bound to.
     *
     * @return Enumerable<array-key, mixed>
     */
    public static function self(mixed $collection): Enumerable
    {
        if (! $collection instanceof Enumerable) {
            throw new \LogicException('Decision collection macros must be called on a collection.');
        }

        return $collection;
    }

    /**
     * @return \Closure(BatchDecision): mixed
     */
    public static function asks(Question $question): \Closure
    {
        return static fn(BatchDecision $batch): BatchDecision => $batch->ask($question);
    }

    public static function noul(string|Noul $question, mixed $instructions): Noul
    {
        return $question instanceof Noul ? $question : Noul::make($question, $instructions);
    }

    /**
     * @param  array<array-key, mixed>|null  $options
     */
    public static function choice(string|Choice $question, mixed $instructions, ?array $options): Choice
    {
        return $question instanceof Choice ? $question : Choice::make($question, $instructions, $options);
    }

    /**
     * @param  array<array-key, mixed>|null  $levels
     */
    public static function score(string|Score $question, mixed $instructions, ?array $levels): Score
    {
        return $question instanceof Score ? $question : Score::make($question, $instructions, $levels === null ? null : array_values($levels));
    }

    public static function compare(float $score, string $operator, float $value): bool
    {
        return match ($operator) {
            '>=' => $score >= $value,
            '>' => $score > $value,
            '<=' => $score <= $value,
            '<' => $score < $value,
            '=', '==' => $score === $value,
            default => $score !== $value,
        };
    }
}
