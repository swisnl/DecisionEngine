# Decision Engine for PHP

Typed, probabilistic decisions for application code: **Choice / Score / Noul** questions evaluated
against your application state, through a fluent API. TypeSafe's **Jev** (a
System One model with calibrated probabilities) is the default engine; **OpenAI's Decisions API**
is a native alternative, and OpenAI (Responses API) and **Anthropic** models are alternative
engines that emulate the same primitives via structured output.


[![PHP from Packagist](https://img.shields.io/packagist/php-v/swisnl/decision-engine.svg)](https://packagist.org/packages/swisnl/decision-engine)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/swisnl/decision-engine.svg)](https://packagist.org/packages/swisnl/decision-engine)
[![Buy us a tree](https://img.shields.io/badge/Treeware-%F0%9F%8C%B3-lightgreen.svg)](https://plant.treeware.earth/swisnl/decision-engine)
[![Made by SWIS](https://img.shields.io/badge/%F0%9F%9A%80-made%20by%20SWIS-%230737A9.svg)](https://www.swis.nl)

```php
use Swis\DecisionEngine\Decision;

$outcome = Decision::for(['message' => $ticket->body, 'order' => $order->toArray()])
    ->choice('department', 'Which team should handle this?', [
        'returns'  => 'Exchanges, wrong or damaged items',
        'shipping' => 'Delivery status, delays, lost packages',
        'billing'  => 'Charges, invoices, payment problems',
        'other'    => null,
    ])
    ->score('severity', 'How severe is the reported issue?', [
        'Cosmetic; no impact to functionality',
        'Broken or degraded feature, but workaround exists',
        'Blocking issue; no workaround exists',
    ])
    ->noul('wants_human', 'Is the customer asking for a human agent?')
    ->decide();

$outcome->department->choice;          // 'billing'
$outcome->department->confidence;      // 0.82
$outcome->severity->score;             // 1.43
$outcome->severity->normalized();      // 0.715
$outcome->wants_human->isTrue(0.9);    // false  (noul 0.84 < 0.9)
$outcome->usage->inputTokens;          // 360
$outcome->model;                       // 'jev-1.13.0'
```

The philosophy, borrowed from TypeSafe: **code stays in control; the model answers narrow, typed
questions.** The library never asks a model to generate text, never lets it choose the workflow and
never hides branching logic. Thresholds, weights and routing are *your* code, written against typed
answers.

- [Installation](#installation)
- [Quick start](#quick-start)
- [Choosing an engine and model](#choosing-an-engine-and-model)
- [Questions](#questions)
- [Reading answers](#reading-answers)
- [Typed outcomes](#typed-outcomes)
- [Escape hatches](#escape-hatches)
- [Serialization](#serialization)
- [Parallelism](#parallelism)
- [Errors and retries](#errors-and-retries)
- [Testing](#testing)
- [Laravel](#laravel)
- [Which primitive, and what the model cannot do](#which-primitive-and-what-the-model-cannot-do)
- [Calibrated vs. uncalibrated engines](#calibrated-vs-uncalibrated-engines)

## Installation

```bash
composer require swis/decision-engine
```

Requires PHP 8.3+, `ext-curl` and `ext-json`. Nothing else for the core; the Laravel bridge is
auto-discovered when `illuminate/support` (11, 12 or 13) is present.

Set `TYPESAFE_API_KEY` and you are done:

```bash
export TYPESAFE_API_KEY=ts_...
```

Optional: `TYPESAFE_BASE_URL`, `TYPESAFE_DEFAULT_MODEL` (default `jev-latest`), `OPENAI_API_KEY`,
`ANTHROPIC_API_KEY`, `DECISION_ENGINE` (default engine name), `DECISION_ENGINE_TRANSPORT`.

## Quick start

Everything is `state + questions → answers`. The static entry point builds a `Client` lazily from
`config/decision-engine.php` and the environment; for dependency injection use a `Client` instance
with the identical API:

```php
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Config;
use Swis\DecisionEngine\Engines\EngineManager;

$client = Client::fromConfig(Config::fromEnv());
// or: new Client(EngineManager::fromArray($configArray), new CurlTransport());

$client->for($state)->noul('urgent', 'Is this urgent?')->decide();
```

State can be a string, an array, or anything `JsonSerializable` / `Stringable`. Jev rejects a
`null` state (validation catches it before sending); pass `''` or `[]` when the questions carry all
the context. Send only
the fields the questions need (`State::from($ticket)->only(['message'])`); irrelevant state
distracts the model and costs tokens.

## Choosing an engine and model

```php
Decision::for($state)->using('luna')->…                    // named engine from config
Decision::for($state)->using(new JevEngine($apiKey))->…    // an Engine instance
Decision::for($state)->model('jev-1.13.0')->…              // override the engine's model
Decision::for($state)->using('haiku')->model('claude-haiku-4-5')->…
```

Engines are resolved by an `EngineManager` with the built-in drivers `jev`, `openai`,
`openai_responses` and `anthropic`, and config-defined *named* engines:

| Name (default config) | Driver | API | Default model | Calibrated |
|---|---|---|---|---|
| `jev` (default) | `jev` | TypeSafe `/v1/systemone` | `jev-latest` | **yes** |
| `luna` | `openai` | OpenAI Decisions `/v1/decisions` (beta) | `gpt-6-luna` | **yes** |
| `luna-responses` | `openai_responses` | OpenAI Responses `/v1/responses` | `gpt-5.6-luna` | no |
| `haiku` | `anthropic` | Anthropic Messages | `claude-haiku-4-5` | no |

The `openai` driver uses OpenAI's [Decisions API](https://developers.openai.com/api/docs/guides/decisions),
which answers the same three primitives natively: `noul` is sent as a `predicate`, `choice` and
`score` map one to one (score levels are 0-indexed, like Jev; same limits of 255 options and 2–10
levels). Structured descriptions and noul `true` / `false` criteria are rendered as plain text, and
the state is sent as `input` (JSON-encoded when it is not a string; a `null` state is rejected).
The API rejects unknown body fields, so `merge_body` only works for fields it documents. The `openai_responses` driver
asks a general model to fill a JSON schema instead, and works with any Responses API model.

Register your own with `EngineManager::extend('name', fn (array $config): Engine => …)` or
`register('name', $engineInstance)`. Switching engines with `using()` clears a previously set
model (a `jev-latest` copied from the playground makes no sense for OpenAI); call `model()` after
`using()`.

## Questions

TypeSafe vocabulary compatible. Three primitives:

| Type | Ask | Answer |
|---|---|---|
| `choice` | pick one option from a fixed set (≤ 255) | `choice`, `probabilities` per option, `confidence` |
| `score` | rate against 2–10 ordered, descriptive levels | `score` (Σ level × p, fractional), `legend`, `probabilities`, `confidence` |
| `noul` | a yes/no statement | `noul` = probability of yes (no server confidence) |

Shorthand on the builder, or richer immutable question objects for structured descriptions
(`what / not_for / examples` are the conventions recommend):

```php
use Swis\DecisionEngine\Questions\{Choice, Score, Noul};

Decision::for($state)
    ->ask(
        Choice::make('department', 'Which team should handle this?')
            ->option('returns', what: 'Exchanges, wrong or damaged items', notFor: 'Refund requests', examples: ['wrong size', 'arrived broken'])
            ->option('billing', 'Charges, invoices, payment problems')
            ->option('other'),                                  // null description
    )
    ->ask(
        Score::make('severity', 'How severe is the reported issue?')
            ->level('Cosmetic; no impact to functionality')
            ->level(what: 'Blocking issue; no workaround exists', examples: ['cannot log in', 'data loss']),
    )
    ->ask(
        Noul::make('same_as_record_18')
            ->withInstructions(['potential_duplicate' => $record, 'question' => 'Is the resume for the same person as `potential_duplicate`?'])
            ->criteria(true: 'Mentions a prior attempt or ticket', false: 'No sign of previous contact'),
    )
    ->decide();
```

Instructions and descriptions accept `string | array | null | JsonSerializable | Stringable`.
Question objects are immutable (withers); the `Decision` builder is mutable for ergonomics and
offers `->clone()` for branching. `->yesNo()` is an alias for `->noul()`.

Validation runs client-side before any I/O and throws `InvalidDecisionException` with an
`->errors()` map keyed by question id. `->lint()` returns advisory `Warning`s for known model
weaknesses (numbers in criteria, missing catch-all option, huge state) and never runs automatically.

## Reading answers

```php
$outcome->department            // ChoiceAnswer  (also $outcome['department'], $outcome->answer('department'), $outcome->choice('department'))
    ->choice ->probabilities ->confidence
    ->is('billing') ->probability('billing') ->ranked() ->top(3) ->isConfident(0.9)

$outcome->severity              // ScoreAnswer
    ->score ->legend ->probabilities ->confidence
    ->normalized() ->level() ->levelDescription() ->probabilityAtLeast(2) ->atLeast(1.5) ->isConfident(0.9)

$outcome->wants_human           // NoulAnswer
    ->noul ->isTrue(0.5) ->isFalse(0.5) ->isUncertain(0.2, 0.8) ->toBool(0.5) ->certainty()   // |2p−1|, derived client-side

$outcome->answer('x')->raw() ->get('some_new_field')   // any answer; unknown types decode to GenericAnswer

$outcome->answers ->ids() ->has('id') ->only([...]) ->model ->engine
$outcome->usage    // Usage{inputTokens, outputTokens}
$outcome->meta     // Meta{calibrated, requestId, latencyMs, extra}
```

Every answer has `certainty()` (server confidence, or `|2·noul − 1|` for noul) and `band()` →
`Certainty::High | Medium | Low`. The default boundaries (`> 0.9`, `≥ 0.5`) come from
`config('decision-engine.thresholds')` and can be passed per call: `->band(new Thresholds(0.95, 0.6))`.

## Typed outcomes

Declare the questions once, as attributes on a class, and get the answers back as typed
properties: PHPStan-clean, reusable across engines, and one place to read what is asked.

```php
use Swis\DecisionEngine\Answers\ScoreAnswer;
use Swis\DecisionEngine\Attributes\{Choice, Noul, Option, Score};
use Swis\DecisionEngine\Typed\TypedOutcome;

enum Department: string
{
    #[Option('Exchanges, wrong or damaged items', notFor: 'Refund requests', examples: ['arrived broken'])]
    case Returns = 'returns';

    #[Option('Charges, invoices, payment problems')]
    case Billing = 'billing';

    case Other = 'other';
}

final class TicketTriage extends TypedOutcome
{
    #[Choice('Which team should handle this?')]
    public readonly Department $department;              // options come from the enum's cases

    #[Choice('Which language is the message in?', options: ['nl' => 'Dutch', 'en' => 'English', 'other' => null])]
    public readonly string $language;                     // the chosen option

    #[Score('How severe is the issue?', levels: ['Cosmetic', 'Degraded, workaround exists', 'Blocking'])]
    public readonly ScoreAnswer $severity;               // full answer

    #[Noul('Is the customer asking for a human agent?')]
    public readonly float $wants_human;                   // probability of yes
}

$triage = Decision::for($state)->decideAs(TicketTriage::class);   // TicketTriage
$triage->department;                  // Department::Billing
$triage->severity->atLeast(1.5);
$triage->outcome();                   // the underlying Outcome: usage, meta, raw, toArray()

Decision::forEach($tickets)->decideAs(TicketTriage::class);      // array<key, TicketTriage>
Decision::forEach($tickets)->settleAs(TicketTriage::class);      // array<key, TicketTriage|Throwable>
Decision::for($state)->ask(...TicketTriage::questions())->lint(); // or ->toRequest() for a dry run
TicketTriage::fromOutcome($outcome); TicketTriage::fromArray($outcome->toArray());
```

The property type decides what you get:

| Attribute | Property type | Value |
|---|---|---|
| `#[Choice]` | `ChoiceAnswer` | full answer; `->as(Department::class)` gives the enum case |
| | `string` | the chosen option |
| | backed enum | the chosen case (string or int backed) |
| `#[Score]` | `ScoreAnswer` / `float` | full answer / expected score |
| `#[Noul]` | `NoulAnswer` / `float` | full answer / probability of yes |

`options` is an array of option → description, or a backed enum class (the default for an enum
property, with descriptions from `#[Option]` on its cases). `id:` overrides the question id, which
defaults to the property name. Mistakes in the class (an unsupported or nullable type, a choice
without options, an enum property with other options, a reused id) throw
`InvalidDecisionException` before anything is sent.

Mapping is strict: an answer that is missing, of another type or not a case of the enum throws
`OutcomeMismatchException`. A missing answer is re-asked first, see [retries](#errors-and-retries).
Other properties on the class are left alone and constructors are not called.

`readonly` properties are assigned by the library, which PHPStan cannot see. The package's
`extension.neon` teaches it that; it loads automatically with
[phpstan/extension-installer](https://github.com/phpstan/extension-installer), otherwise add
`vendor/swis/decision-engine/extension.neon` to `includes` in your `phpstan.neon`.

## Escape hatches

```php
Decision::for($state)
    ->rawQuestion('anything', ['type' => 'choice', 'instructions' => …, 'criteria' => …, 'future_field' => 1]) // passthrough
    ->withPayload(fn (array $payload): array => $payload + ['experimental' => true])  // mutate the canonical body last-minute
    ->mergePayload(['model' => 'jev-preview'])                                        // shallow merge (the SDKs' extra_body)
    ->withHeaders(['X-Trace-Id' => $traceId])
    ->withOptions(['timeout' => 3.0, 'retry' => ['maxRetries' => 0]])                 // transport RequestOptions
    ->engineOptions(['reasoning' => ['effort' => 'low']])                              // opaque, handed to the Engine
    ->engineOptions(['merge_body' => ['store' => false]])                              // merged into the *provider* body last
    ->toRequest();          // PreparedRequest {method, url, headers, body} — nothing is sent; ->toCurlCommand() redacts keys

$outcome->raw();            // full decoded body
$outcome->response();       // HttpResponse {status, headers, body}
$outcome->request();        // the PreparedRequest that produced it
```

`withPayload()` / `mergePayload()` operate on the canonical payload `{state, model, questions}` —
for Jev that *is* the wire body. Provider-specific tweaks for OpenAI/Anthropic go through
`engineOptions(['merge_body' => …])`.

Custom transports implement `Contracts\Transport` (and `ConcurrentTransport` for parallelism).
Shipped: `CurlTransport` (default, sync + `curl_multi`), `Psr18Transport` (bring your Guzzle /
Symfony client with its middleware; configure `http_errors => false`), and in Laravel
`LaravelHttpTransport` (so `Http::fake()` works).

## Serialization

The array **is** the contract: `toArray()` produces the TypeSafe wire payload plus optional
`engine`, `model`, `options`, `engine_options` and `payload.merge`; `fromArray()` rebuilds it.
Anything you can build fluently you can persist, queue, diff, store in a DB and replay.

```php
$array = $decision->toArray();
$json  = $decision->toJson();
$again = Decision::fromArray($array);   // identical builder state, including engine/model/options
$again = Decision::fromJson($json);

Decision::fromArray(['state' => …, 'model' => 'jev-latest', 'questions' => […]]); // bare playground payload works too

$outcome->toArray(); Outcome::fromArray($array);   // outcomes round-trip as well (caches, fixtures, audit logs)
```

`toArray()` is stable (same input → same bytes after `Json::canonical()`), never contains closures
(`withPayload()` closures are applied and flattened at serialization time) and preserves question
order and unknown fields.

## Parallelism

Many questions in **one** request is the primary form of parallelism, just add questions. Many
*requests* in parallel use Fibers over `curl_multi`, with the exact same `decide()` call:

```php
// B. Many decisions concurrently (keys and order preserved)
$outcomes = Decision::parallel([
    'a' => Decision::for($stateA)->noul('urgent', 'Is this urgent?'),
    'b' => Decision::for($stateB)->using('haiku')->choice('lang', 'Language?', ['nl' => null, 'en' => null]),
], concurrency: 10);

// C. Same questions, many states
$outcomes = Decision::forEach($tickets->keyBy('id'), fn (Ticket $t) => ['message' => $t->body])
    ->noul('is_spam', 'Is this message spam?')
    ->decide(concurrency: 20);                                   // array<id, Outcome>

// D. Sequential code per item, items run concurrently (cascades, fetch-then-ask)
$results = Decision::parallel([
    fn () => $this->triage($ticket1),   // every decide() inside is scheduled on the loop, nested parallel() too
    fn () => $this->triage($ticket2),
]);

// E. Don't throw on individual failures (Promise.allSettled)
$settled = Decision::settle([...]);      // array<key, Outcome|Throwable>
```

Inside a scheduler-owned Fiber `decide()` suspends; outside it blocks. `concurrency` bounds
in-flight HTTP requests; a task sleeping in retry backoff holds no slot, so a 429 storm never
starves other tasks. Failures are collected and thrown after all tasks settle as
`ParallelFailedException` (`->outcomes()`, `->failures()`). A non-concurrent transport falls back
to sequential execution with a PSR-3 warning. See `examples/parallel.php` for timing evidence.

## Errors and retries

```
DecisionEngineException (marker)
├── ConfigurationException                 missing/invalid config (->key)
├── InvalidDecisionException               client-side validation (->errors())
├── TransportException                      ConnectionException · TimeoutException (->timeout)
├── ApiException (->status ->headers ->requestId ->engine ->body())
│   ├── BadRequestException 400 · AuthenticationException 401 · PermissionDeniedException 403 · NotFoundException 404
│   ├── UnprocessableEntityException 422 · RateLimitException 429 (->retryAfterMs) · OverloadedException 529 · ServerException 5xx
│   └── ResponseValidationException        2xx with an invalid body (->fieldPath, e.g. answers.tone.confidence)
├── OutcomeMismatchException               an Outcome does not fit a TypedOutcome class (->questionId)
└── ParallelFailedException                (->outcomes() ->failures())
```

Retries follow the official SDK defaults: 2 retries on 408/429/5xx, connection errors and
timeouts, exponential backoff 500 ms → 5 s with 25 % jitter, `Retry-After` / `retry-after-ms`
honoured up to 60 s, 10 s per-attempt timeout. Every outcome must answer every question: a 2xx
response that is invalid (a missing answer, a malformed body, LLM output that does not fit the
schema) is re-asked once without backoff (`retry.invalid_responses`, `0` to disable) before the
`ResponseValidationException` is thrown. Configure globally in `retry` or per request with
`->withOptions(['retry' => [...]])`. API keys never appear in exceptions, logs or `var_dump()`.

## Testing

```php
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Testing\Fake;

Decision::fake([
    'department'  => Fake::choice('billing', confidence: 0.95),
    'severity'    => Fake::score(1.4, levels: 3),
    'wants_human' => Fake::noul(0.1),
]);
Decision::fake(fn (DecisionRequest $r) => ['urgent' => Fake::noul($r->hasQuestion('vip') ? 0.99 : 0.2)]);  // per request
Decision::fake();                          // every question gets a neutral default answer

$fake = Decision::fake(...);
$fake->assertDecided(fn (DecisionRequest $r) => $r->hasQuestion('department'));
$fake->assertDecidedCount(1);
$fake->assertNothingDecided();
Decision::restore();                       // or use the InteractsWithDecisions trait
```

While a fake is installed it answers **every** decision: the static entry points, the Laravel
facade, injected or hand-built `Client`s, and any engine passed to `using()` (a name or an
instance). Nothing is sent, and client hooks still run, so Laravel's `Decided` / `DecisionFailed`
events fire as in production. Assert on the requested engine with `$r->engine === 'luna'`.

Fake answers are rendered against the real question so probabilities match the requested choice
and confidence, and they go through the real answer parser. Lower level: `FakeTransport` /
`FakeConcurrentTransport` (scripted responses and latency), `FakeEngine`, and `RecordingTransport`
to record real responses as fixtures and replay them.

## Laravel

The service provider is auto-discovered. Publish the config and add your keys to `.env`:

```bash
php artisan vendor:publish --tag=decision-engine-config
php artisan decision-engine:models
php artisan decision-engine:try storage/decisions/triage.json --engine=luna --dry-run
```

```php
use Swis\DecisionEngine\Laravel\Facades\Decision;

Decision::for($state)->noul('urgent', 'Is this urgent?')->decide();

Decision::fake(['urgent' => Fake::noul(0.9)]);
Decision::assertDecided(fn (DecisionRequest $r) => $r->hasQuestion('urgent'));
```

### Collections

The bridge adds macros to `Collection`, `LazyCollection` and Eloquent collections that decide about
every item and filter, sort, group or partition on the answer. Each item is one decision; items are
decided concurrently, and a `LazyCollection` is decided in chunks of 100 so it streams.

```php
$tickets->whereNoul('is_spam', 'Is this message spam?', threshold: 0.9, state: 'body');
$tickets->whereNotNoul('is_spam', 'Is this message spam?', state: 'body');
$tickets->whereChoice('department', 'Which team?', $options, is: ['billing', 'returns'], minConfidence: 0.7);
$tickets->whereScore('severity', 'How severe?', $levels, '>=', 1.5, state: ['subject', 'body']);

$results->sortByScoreDesc('relevance', "How relevant is this to: {$query}?", $levels, state: 'summary');
$results->sortByNoul(...);  $results->sortByNoulDesc(...);  $results->sortByScore(...);
$tickets->groupByChoice('department', 'Which team?', $options, state: 'body');   // keyed by option
[$spam, $ham] = $tickets->partitionByNoul('is_spam', 'Is this spam?', state: 'body');

// several questions, any predicate
$tickets->whereDecision(
    fn (BatchDecision $d) => $d->noul('urgent', 'Is this urgent?')->score('severity', 'How severe?', $levels),
    fn (Outcome $o) => $o->urgent->isTrue(0.8) && $o->severity->atLeast(1.5),
    state: fn (Ticket $t) => ['message' => $t->body],
);
$outcomes = $tickets->decide(fn (BatchDecision $d) => …, state: 'body');   // Collection<key, Outcome>
```

A question is an id with instructions (and options or levels), or a question object such as
`Noul::make(...)->criteria(...)`. Keys are preserved. Every macro also takes:

- `state:` a closure `($item, $key)`, an attribute name or a list of names (read with `data_get`).
  Without it, an item implementing `ProvidesDecisionState` sends `toDecisionState()` and anything else
  is sent as-is, except Eloquent models: those throw instead of sending every column.
- `using:` / `model:` the engine and model; `concurrency:` the batch concurrency.
- `settle:` by default any failure throws `ParallelFailedException` after the batch ran; `true` leaves
  failed items out of the result, and a closure `(\Throwable $e, $item, $key)` is also told about each.

There are deliberately no query builder macros: the model cannot run in SQL, so fetch first
(`->get()` or `->lazy()`). For a single model, `HasDecisions` adds `$ticket->decision()`, a
`Decision` with `toDecisionState()` as its state:

```php
class Ticket extends Model implements ProvidesDecisionState
{
    use HasDecisions;

    public function toDecisionState(): mixed
    {
        return ['subject' => $this->subject, 'message' => $this->body];
    }
}

Ticket::where('status', 'open')->lazy()->whereNoul('is_spam', 'Is this spam?', settle: true);
$ticket->decision()->noul('urgent', 'Is this urgent?')->decide();
```

`Decided` and `DecisionFailed` events are dispatched for every decision. With
`DECISION_ENGINE_TRANSPORT=laravel` requests go through the `Http` client so `Http::fake()`
intercepts them; that transport is sequential, so `parallel()` runs one request at a time.

## Which primitive, and what the model cannot do

- **Choice** when the answer is one of a known set; add an `other`/`none` option so "none fits"
  shows up as a clear answer instead of low confidence.
- **Score** when the property is ordinal; describe each level, lowest first. Compose complex
  judgments from several atomic scores and weight them in code.
- **Noul** for a single yes/no property. `P(x) + P(¬x)` across *separate* questions is not
  guaranteed to be 1, so there is deliberately no complement helper.

Jev reads questions literally, does no arithmetic, date comparison or counting, and does not
generate values. Extract facts in code and ask the model to *judge* them; `->lint()` flags the
common traps. Questions are evaluated independently and in parallel against the same state, in
one ~100 ms call. Detailed guidance: <https://docs.typesafe.ai>.

## Calibrated vs. uncalibrated engines

Jev's probabilities are **calibrated**: confidence-gating on them is the point. The OpenAI
Responses and Anthropic engines ask a general LLM to fill a JSON schema with probabilities in one
call; those numbers are **self-reported and uncalibrated**. OpenAI's Decisions API (`openai`) is a
dedicated System One endpoint and is treated as calibrated. Every outcome tells you which you got:

```php
if (! $outcome->meta->calibrated) {
    // treat confidence as a hint, not a gate
}
```

The LLM engines are fully supported alternatives when you prefer another provider; just don't treat
their confidence as calibrated. Gate on it only after checking it against labelled data.

## Development

```bash
composer check      # pint --test, phpstan (level max), pest
php examples/fan-out.php   # every example runs offline against a fake unless TYPESAFE_API_KEY is set
```

Copy `.env.example` to `.env` and fill in your keys: both the test suite and the examples load it
(without overriding real environment variables), so `php examples/fan-out.php` hits the live API.

Live tests run when `TYPESAFE_API_KEY` / `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` are set and only
assert response shape, never exact numbers.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Joris Meijer](https://github.com/jormeijer)
- [All Contributors](../../contributors)

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

This package is [Treeware](https://treeware.earth). If you use it in production, then we ask that you [**buy the world a tree**](https://plant.treeware.earth/swisnl/agents-sdk) to thank us for our work. By contributing to the Treeware forest you’ll be creating employment for local families and restoring wildlife habitats.

## SWIS :heart: Open Source

[SWIS](https://www.swis.nl) is a web agency from Leiden, the Netherlands. We love working with open source software.
