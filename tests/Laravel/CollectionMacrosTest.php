<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Swis\DecisionEngine\Concurrency\BatchDecision;
use Swis\DecisionEngine\Exceptions\ParallelFailedException;
use Swis\DecisionEngine\Laravel\Facades\Decision;
use Swis\DecisionEngine\Laravel\HasDecisions;
use Swis\DecisionEngine\Laravel\ProvidesDecisionState;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Testing\Fake;

enum Department: string
{
    case Billing = 'billing';
    case Returns = 'returns';
}

class CollectionMacroTicket extends Model implements ProvidesDecisionState
{
    use HasDecisions;

    protected $guarded = [];

    public function toDecisionState(): mixed
    {
        return ['message' => $this->getAttribute('body')];
    }
}

class CollectionMacroUser extends Model
{
    protected $guarded = [];
}

/**
 * Fake answers keyed by the message in the state: [spam noul, department, department confidence, severity].
 *
 * @var array<string, array{float, string, float, float}>
 */
const TICKETS = [
    'buy now' => [0.95, 'billing', 0.9, 0.2],
    'refund please' => [0.3, 'returns', 0.6, 1.8],
    'invoice wrong' => [0.1, 'billing', 0.95, 1.2],
    'broken item' => [0.6, 'returns', 0.9, 1.0],
];

beforeEach(function (): void {
    Decision::fake(function (DecisionRequest $r): array|RuntimeException {
        $state = $r->state->value();
        $message = is_array($state) ? $state['message'] ?? $state['body'] : $state;

        if ($message === 'boom') {
            return new RuntimeException('engine down');
        }

        [$spam, $department, $confidence, $severity] = TICKETS[$message];

        return [
            'is_spam' => Fake::noul($spam),
            'department' => Fake::choice($department, $confidence),
            'severity' => Fake::score($severity),
        ];
    });

    $this->tickets = collect(['a' => 'buy now', 'b' => 'refund please', 'c' => 'invoice wrong', 'd' => 'broken item']);
    $this->departments = ['billing' => null, 'returns' => null, 'other' => null];
    $this->levels = ['minor', 'major', 'blocking'];
});

it('filters on a noul, keeping keys', function (): void {
    expect($this->tickets->whereNoul('is_spam', 'Is this spam?')->all())->toBe(['a' => 'buy now', 'd' => 'broken item'])
        ->and($this->tickets->whereNoul('is_spam', 'Is this spam?', threshold: 0.9)->all())->toBe(['a' => 'buy now'])
        ->and($this->tickets->whereNotNoul('is_spam', 'Is this spam?')->keys()->all())->toBe(['b', 'c'])
        ->and($this->tickets->whereNoul(Noul::make('is_spam', 'Is this spam?')->criteria(true: 'Sells something'))->keys()->all())->toBe(['a', 'd']);

    Decision::assertDecided(fn(DecisionRequest $r): bool => $r->hasQuestion('is_spam') && $r->questions->count() === 1);
});

it('filters on a choice and a score', function (): void {
    expect($this->tickets->whereChoice('department', 'Which team?', $this->departments, is: 'billing')->keys()->all())->toBe(['a', 'c'])
        ->and($this->tickets->whereChoice('department', 'Which team?', $this->departments, is: Department::Returns, minConfidence: 0.8)->keys()->all())->toBe(['d'])
        ->and($this->tickets->whereChoice('department', 'Which team?', $this->departments, is: ['billing', Department::Returns])->count())->toBe(4)
        ->and($this->tickets->whereScore('severity', 'How severe?', $this->levels, '>=', 1.2)->keys()->all())->toBe(['b', 'c'])
        ->and($this->tickets->whereScore('severity', 'How severe?', $this->levels, '<', 1.0)->keys()->all())->toBe(['a'])
        ->and($this->tickets->whereScore('severity', 'How severe?', $this->levels, '!=', 1.0)->keys()->all())->toBe(['a', 'b', 'c'])
        ->and(fn() => $this->tickets->whereChoice('department', 'Which team?', $this->departments))->toThrow(InvalidArgumentException::class, 'is:')
        ->and(fn() => $this->tickets->whereScore('severity', 'How severe?', $this->levels, '~', 1.0))->toThrow(InvalidArgumentException::class, 'operator');
});

it('sorts by a score or a noul', function (): void {
    expect($this->tickets->sortByScore('severity', 'How severe?', $this->levels)->keys()->all())->toBe(['a', 'd', 'c', 'b'])
        ->and($this->tickets->sortByScoreDesc('severity', 'How severe?', $this->levels)->keys()->all())->toBe(['b', 'c', 'd', 'a'])
        ->and($this->tickets->sortByNoul('is_spam', 'Is this spam?')->keys()->all())->toBe(['c', 'b', 'd', 'a'])
        ->and($this->tickets->sortByNoulDesc('is_spam', 'Is this spam?')->all())->toBe(['a' => 'buy now', 'd' => 'broken item', 'b' => 'refund please', 'c' => 'invoice wrong']);
});

it('groups by a choice and partitions on a noul', function (): void {
    $groups = $this->tickets->groupByChoice('department', 'Which team?', $this->departments);

    expect($groups->map->all()->all())->toBe(['billing' => ['buy now', 'invoice wrong'], 'returns' => ['refund please', 'broken item']])
        ->and($this->tickets->groupByChoice('department', 'Which team?', $this->departments, preserveKeys: true)->map->keys()->map->all()->all())
        ->toBe(['billing' => ['a', 'c'], 'returns' => ['b', 'd']]);

    [$spam, $ham] = $this->tickets->partitionByNoul('is_spam', 'Is this spam?', threshold: 0.5);

    expect($spam->all())->toBe(['a' => 'buy now', 'd' => 'broken item'])
        ->and($ham->all())->toBe(['b' => 'refund please', 'c' => 'invoice wrong']);
});

it('decides several questions with whereDecision and decide', function (): void {
    $keys = [];
    $state = function (string $message, string $key) use (&$keys): array {
        $keys[] = $key;

        return ['message' => $message];
    };

    $urgentBilling = $this->tickets->whereDecision(
        fn(BatchDecision $d) => $d->noul('is_spam', 'Is this spam?')->choice('department', 'Which team?', $this->departments),
        fn(Outcome $o, string $message, string $key): bool => $o->department->is('billing') && $o->is_spam->isFalse(),
        state: $state,
    );

    expect($urgentBilling->all())->toBe(['c' => 'invoice wrong'])
        ->and($keys)->toBe(['a', 'b', 'c', 'd']);

    $outcomes = $this->tickets->decide(fn(BatchDecision $d) => $d->score('severity', 'How severe?', $this->levels));

    expect($outcomes)->toBeInstanceOf(Collection::class)
        ->and($outcomes->map(fn(Outcome $o): float => $o->severity->score)->all())->toBe(['a' => 0.2, 'b' => 1.8, 'c' => 1.2, 'd' => 1.0]);
});

it('reads state from attributes and passes the engine and model', function (): void {
    $rows = collect([['id' => 1, 'body' => 'buy now', 'secret' => 'x'], ['id' => 2, 'body' => 'invoice wrong', 'secret' => 'y']]);

    expect($rows->whereNoul('is_spam', 'Is this spam?', state: 'body', using: 'luna', model: 'gpt-test')->pluck('id')->all())->toBe([1])
        ->and($rows->whereNoul('is_spam', 'Is this spam?', state: ['body'])->pluck('id')->all())->toBe([1])
        ->and(fn() => $rows->whereNoul('is_spam', 'Is this spam?', state: ['body' => 1]))->toThrow(InvalidArgumentException::class, 'state:');

    Decision::assertDecided(fn(DecisionRequest $r): bool => $r->engine === 'luna' && $r->model === 'gpt-test' && $r->state->value() === 'buy now', times: 1);
    Decision::assertDecided(fn(DecisionRequest $r): bool => $r->state->value() === ['body' => 'invoice wrong'], times: 1);
    Decision::assertNotDecided(fn(DecisionRequest $r): bool => str_contains((string) json_encode($r->state->value()), 'secret'));
});

it('works on Eloquent collections and models', function (): void {
    $tickets = new EloquentCollection([
        new CollectionMacroTicket(['body' => 'buy now']),
        new CollectionMacroTicket(['body' => 'invoice wrong']),
    ]);

    $spam = $tickets->whereNoul('is_spam', 'Is this spam?');

    expect($spam)->toBeInstanceOf(EloquentCollection::class)
        ->and($spam->map->getAttribute('body')->all())->toBe(['buy now'])
        ->and($tickets->first()->decision()->noul('is_spam', 'Is this spam?')->decide()->is_spam->noul)->toBe(0.95);

    Decision::assertDecided(fn(DecisionRequest $r): bool => $r->state->value() === ['message' => 'buy now']);

    $users = new EloquentCollection([new CollectionMacroUser(['body' => 'buy now'])]);

    expect(fn() => $users->whereNoul('is_spam', 'Is this spam?'))->toThrow(InvalidArgumentException::class, 'refusing to send every column')
        ->and($users->whereNoul('is_spam', 'Is this spam?', state: 'body')->count())->toBe(1);
});

it('streams a LazyCollection per chunk', function (): void {
    $source = LazyCollection::times(250, fn(int $i): string => $i % 2 === 0 ? 'buy now' : 'invoice wrong');

    expect($source->whereNoul('is_spam', 'Is this spam?')->take(3)->keys()->all())->toBe([1, 3, 5]);
    Decision::assertDecidedCount(100);

    $sorted = $source->take(4)->sortByNoulDesc('is_spam', 'Is this spam?');
    Decision::assertDecidedCount(100); // deferred until enumerated

    expect($sorted)->toBeInstanceOf(LazyCollection::class)
        ->and($sorted->values()->all())->toBe(['buy now', 'buy now', 'invoice wrong', 'invoice wrong'])
        ->and($source->take(4)->decide(fn(BatchDecision $d) => $d->noul('is_spam', 'Is this spam?'))->count())->toBe(4)
        ->and($source->whereNoul('is_spam', 'Is this spam?')->count())->toBe(125);

    [$spam, $ham] = $source->take(4)->partitionByNoul('is_spam', 'Is this spam?')->all(); // like LazyCollection::partition()
    expect($spam->keys()->all())->toBe([1, 3])->and($ham->keys()->all())->toBe([0, 2]);
});

it('throws on failures by default and skips them when settling', function (): void {
    $tickets = collect(['a' => 'buy now', 'b' => 'boom', 'c' => 'broken item']);

    expect(fn() => $tickets->whereNoul('is_spam', 'Is this spam?'))->toThrow(ParallelFailedException::class, 'engine down');

    $failed = [];
    $report = function (Throwable $e, string $item, string $key) use (&$failed): void {
        $failed[$key] = [$item, $e->getMessage()];
    };

    expect($tickets->whereNoul('is_spam', 'Is this spam?', settle: true)->keys()->all())->toBe(['a', 'c'])
        ->and($tickets->whereNotNoul('is_spam', 'Is this spam?', threshold: 0.9, settle: true)->keys()->all())->toBe(['c'])
        ->and($tickets->sortByNoul('is_spam', 'Is this spam?', settle: $report)->keys()->all())->toBe(['c', 'a'])
        ->and($failed)->toBe(['b' => ['boom', 'engine down']])
        ->and($tickets->decide(fn(BatchDecision $d) => $d->noul('is_spam', 'Is this spam?'), settle: true)->keys()->all())->toBe(['a', 'c']);

    [$spam, $ham] = $tickets->partitionByNoul('is_spam', 'Is this spam?', settle: true);
    expect($spam->keys()->all())->toBe(['a', 'c'])->and($ham->all())->toBe([]);
});
