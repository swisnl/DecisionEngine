# Changelog

All notable changes to `swis/decision-engine` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows semantic versioning.

## [Unreleased]

### Added
- Laravel collection macros on `Collection`, `LazyCollection` and Eloquent collections: `whereNoul`,
  `whereNotNoul`, `whereChoice`, `whereScore`, `whereDecision`, `sortByScore(Desc)`,
  `sortByNoul(Desc)`, `groupByChoice`, `partitionByNoul` and `decide`. Items are decided
  concurrently (a LazyCollection per chunk of 100); `state:` picks what is sent per item and
  `settle:` leaves failed items out instead of throwing.
- `ProvidesDecisionState` and the `HasDecisions` model trait (`$ticket->decision()`).
- `OpenAiEngine` (driver `openai`) for OpenAI's Decisions API (`POST /v1/decisions`, default model
  `gpt-6-luna`, calibrated): `noul` → `predicate`, `choice` and `score` natively, refusals raise a
  `ResponseValidationException`. The `luna` named engine now uses it.
- Typed outcomes: declare questions with `#[Choice]`, `#[Score]` and `#[Noul]` on a `TypedOutcome`
  subclass and call `Decision::for($state)->decideAs(MyClass::class)` (or `forEach()->decideAs()` /
  `settleAs()`). The property type selects the value: the answer object, a string, a backed enum
  (options from its cases, described with `#[Option]`) or a float. Mapping is strict and throws
  `OutcomeMismatchException`; definition errors throw `InvalidDecisionException` before sending.
- `ChoiceAnswer::as(Enum::class)` returns the choice as a backed-enum case.
- `extension.neon`: a PHPStan extension so `readonly` question properties are not reported as
  uninitialized (auto-registered with phpstan/extension-installer).
- Every outcome must answer every asked question; a missing answer is a `ResponseValidationException`.
- `retry.invalid_responses` (default 1): re-ask, without backoff, when a 2xx response fails validation.

### Fixed
- OpenAI / Anthropic: `score` questions (and choices with numeric option names) no longer fail with
  `400 Invalid schema`; every schema `properties` map is now encoded as a JSON object.
- `Decision::fake()` now answers every decision while installed: named engines (`using('luna')`),
  engine instances, injected or hand-built `Client`s and the Laravel container's client. Client hooks
  run under the fake, so Laravel's `Decided` / `DecisionFailed` events are dispatched.
- `Decision::parallel()` / `settle()` / `forEach()` send each decision over the transport of the
  client it is bound to, instead of the default client's transport.
- `forEach()->settle()` no longer throws when the `$toState` closure fails for an item; the failure
  is that key's result (and part of `ParallelFailedException` for `decide()`).
- A `null` state is rejected by validation for Jev (and the fake) instead of failing with a 422.
- API errors include FastAPI validation details (`body.state: Field required`).
- `DecisionRequest::withEngine()` clears the model when the engine changes, like `Decision::using()`.

### Changed
- Package description: OpenAI and Anthropic are alternative engines, not fallbacks.
- **Breaking:** the Responses API engine is renamed `OpenAiEngine` → `OpenAiResponsesEngine`, driver
  `openai` → `openai_responses`; the `openai` driver is now the Decisions API. The Responses API
  setup is available as the `luna-responses` named engine. See [UPGRADE](UPGRADE.md).

## [0.1.0] — 2026-09-22

### Phase 0 — Scaffolding
- Composer package `swis/decision-engine` (PHP ^8.3, ext-curl, ext-json, psr/log).
- Pest 3, PHPStan level max, Pint (PER preset), GitHub Actions CI on PHP 8.3 and 8.4.

### Phase 1 — Domain
- `State` normalization (`only()` / `except()` / `with()`), `Choice` / `Score` / `Noul` / `RawQuestion` value objects, `QuestionSet`, `Validator`, `Capabilities`.
- Canonical array form for `DecisionRequest` (`toArray()` / `fromArray()` / `toJson()` / `fromJson()`), bare TypeSafe wire form accepted, unknown question fields preserved.
- `RetryPolicy` (official SDK defaults) and `RequestOptions`.

### Phase 2 — Answers and Outcome
- `Math` (normalize, argmax, expected score, n-way confidence, noul certainty).
- `ChoiceAnswer`, `ScoreAnswer`, `NoulAnswer`, `GenericAnswer`, `AnswerFactory`, `Certainty` bands with configurable `Thresholds`.
- `Outcome` with property / array / iterator access, typed accessors, `toArray()` / `fromArray()` round-trip.
- `PreparedRequest` (redacted debug output, `toCurlCommand()`), `HttpResponse`, full exception hierarchy.

### Phase 3 — Engines
- `Engine` contract, `JevEngine` (`POST /v1/systemone`, strict response validation with dotted field paths), `ModelsEndpoint`.
- `EngineManager` with drivers, named engines, `extend()` / `register()`; typed `Config` with `fromEnv()`.

### Phase 4 — Execution
- `Client::execute()` pipeline: validate → prepare → hooks → send with retries → interpret → PSR-3 log.
- `CurlTransport` (blocking `send()` + `curl_multi` handle), `Psr18Transport`, `FakeTransport`, pure `Retrier`.
- Opt-in live test against the TypeSafe API when `TYPESAFE_API_KEY` is set.

### Phase 5 — Fluent builder
- `Decision::for()` builder with `choice()` / `score()` / `noul()` / `yesNo()` / `ask()` / `rawQuestion()`, `using()` / `model()`, transport and engine option hatches, `withPayload()` / `mergePayload()`, `toRequest()` dry run, `clone()`, `lint()`.
- `Decision::fromArray()` / `fromJson()` / `fromRequest()`; static client resolver (`resolveClientUsing()`).

### Phase 8 — Testing utilities (pulled forward)
- `Decision::fake()` with `Fake::choice()` / `score()` / `noul()`, resolver closures, `assertDecided*` assertions, `FakeEngine`, `FakeTransport`, `RecordingTransport`, `InteractsWithDecisions`.

### Phase 6 — Concurrency
- `FiberScheduler`: Fibers over one `curl_multi` handle, lazy task start, in-flight concurrency limit, retry backoff as loop timers, error isolation, nested `parallel()` reuse, results in input order.
- `Decision::parallel()` / `settle()` / `forEach()` (`BatchDecision`), `ParallelFailedException`, sequential fallback with a warning for non-concurrent transports.
- `FakeConcurrentTransport` with simulated latency; `examples/parallel.php` prints timing evidence.

### Phase 7 — LLM engines
- `StructuredOutputEngine` base with a fixed System One prompt (`PromptBuilder`), strict JSON schema per question set (`SchemaBuilder`) and `AnswerNormalizer` (clamp, renormalize, argmax, expected score, confidence).
- `OpenAiEngine` (Responses API, `text.format` json_schema, `reasoning.effort: none`, `store: false`) and `AnthropicEngine` (Messages API, `output_config.format` json_schema by default, forced `record_decision` tool via `mode: tool`).
- Every LLM outcome carries `meta.calibrated = false`; refusals, truncation and malformed output become `ResponseValidationException`s with field paths.
- `Decision::using()` now clears an inherited model when the engine changes.

### Phase 9 — Laravel bridge
- `DecisionEngineServiceProvider` (config merge/publish, singletons, static resolver, `Decided` / `DecisionFailed` events), `Decision` facade with fake-aware root and `assertDecided*` helpers.
- `LaravelHttpTransport` so `Http::fake()` intercepts decisions (`DECISION_ENGINE_TRANSPORT=laravel`), `decision-engine:models` and `decision-engine:try` commands.

### Phase 10 — Docs and examples
- README covering quick start, engines, questions, answers, escape hatches, serialization, parallelism, errors, testing, Laravel, primitive guidance and the calibrated/uncalibrated distinction.
- Runnable examples: `fan-out`, `confidence-routing`, `composite-scoring`, `intent-routing`, `cascade`, `parallel` (offline via `Decision::fake()` unless `TYPESAFE_API_KEY` is set).

