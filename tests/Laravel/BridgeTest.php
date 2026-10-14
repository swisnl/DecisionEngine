<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Config;
use Swis\DecisionEngine\Contracts\Transport;
use Swis\DecisionEngine\Decision as FluentDecision;
use Swis\DecisionEngine\Engines\Anthropic\AnthropicEngine;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Engines\Jev\JevEngine;
use Swis\DecisionEngine\Engines\OpenAi\OpenAiEngine;
use Swis\DecisionEngine\Engines\OpenAi\OpenAiResponsesEngine;
use Swis\DecisionEngine\Exceptions\ConnectionException;
use Swis\DecisionEngine\Exceptions\ServerException;
use Swis\DecisionEngine\Laravel\Events\Decided;
use Swis\DecisionEngine\Laravel\Events\DecisionFailed;
use Swis\DecisionEngine\Laravel\Facades\Decision;
use Swis\DecisionEngine\Laravel\LaravelHttpTransport;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Testing\Fake;
use Swis\DecisionEngine\Transport\CurlTransport;

it('registers singletons and resolves named engines from config', function (): void {
    expect($this->app->make(Client::class))->toBe($this->app->make(Client::class))
        ->and($this->app->make(Config::class)->defaultEngine)->toBe('jev')
        ->and($this->app->make(Transport::class))->toBeInstanceOf(CurlTransport::class)
        ->and($this->app->make(EngineManager::class)->engine())->toBeInstanceOf(JevEngine::class)
        ->and($this->app->make(EngineManager::class)->engine('luna'))->toBeInstanceOf(OpenAiEngine::class)
        ->and($this->app->make(EngineManager::class)->engine('luna-responses'))->toBeInstanceOf(OpenAiResponsesEngine::class)
        ->and($this->app->make(EngineManager::class)->engine('haiku'))->toBeInstanceOf(AnthropicEngine::class)
        ->and(FluentDecision::resolveClient())->toBe($this->app->make(Client::class))
        ->and(Decision::engines())->toBe($this->app->make(EngineManager::class))
        ->and(config('decision-engine.thresholds.high'))->toBe(0.9);
});

it('intercepts decisions with Http::fake() when the laravel transport is selected', function (): void {
    config()->set('decision-engine.transport.driver', 'laravel');
    $this->app->forgetInstance(Transport::class);
    $this->app->forgetInstance(Client::class);

    Http::fake(['api.typesafe.ai/v1/systemone' => Http::response($this->fixture('quickstart-response'), 200, ['x-typesafe-request-id' => 'req_http'])]);
    Event::fake();

    expect($this->app->make(Transport::class))->toBeInstanceOf(LaravelHttpTransport::class);

    $outcome = Decision::for('Hi, my Stripe integration keeps failing')
        ->choice('department', 'Which team?', ['billing' => null, 'technical' => null, 'sales' => null])
        ->score('frustration', 'How frustrated?', ['calm', 'frustrated', 'angry'])
        ->noul('is_urgent', 'Urgent?')
        ->decide();

    expect($outcome->department->choice)->toBe('technical')
        ->and($outcome->meta->requestId)->toBe('req_http')
        ->and($outcome->meta->latencyMs)->toBeFloat();

    Http::assertSent(fn(HttpRequest $request): bool => $request->url() === 'https://api.typesafe.ai/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer sk-test')
        && $request['model'] === 'jev-latest'
        && array_keys($request['questions']) === ['department', 'frustration', 'is_urgent']);

    Event::assertDispatched(Decided::class, fn(Decided $e): bool => $e->outcome->department->choice === 'technical' && $e->request->hasQuestion('department'));
    Event::assertNotDispatched(DecisionFailed::class);
});

it('dispatches DecisionFailed and maps HTTP client errors', function (): void {
    config()->set('decision-engine.transport.driver', 'laravel');
    config()->set('decision-engine.retry.max_retries', 0);
    $this->app->forgetInstance(Transport::class);
    $this->app->forgetInstance(Client::class);
    $this->app->forgetInstance(Config::class);

    Http::fake(['api.typesafe.ai/*' => Http::response(['message' => 'down'], 503)]);
    Event::fake();

    expect(fn() => Decision::for('x')->noul('n', 'q')->decide())->toThrow(ServerException::class, 'down');
    Event::assertDispatched(DecisionFailed::class, fn(DecisionFailed $e): bool => $e->exception instanceof ServerException);

    Http::fake(fn() => throw new Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect'));
    expect(fn() => Decision::for('x')->noul('n', 'q')->decide())->toThrow(ConnectionException::class);
});

it('fakes through the facade with Http::fake-style assertions', function (): void {
    Decision::fake(['urgent' => Fake::noul(0.95)]);

    $outcome = Decision::for('x')->noul('urgent', 'Urgent?')->decide();

    expect($outcome->urgent->noul)->toBe(0.95)
        ->and($outcome->engine)->toBe('fake')
        ->and(FluentDecision::resolveClient())->toBe($this->app->make(Client::class)) // the container's client, answering from the fake
        ->and($this->app->make(Client::class)->engine()->name())->toBe('fake');

    Decision::assertDecided(fn(DecisionRequest $r) => $r->hasQuestion('urgent'));
    Decision::assertDecidedCount(1);
    Decision::assertNotDecided(fn(DecisionRequest $r) => $r->hasQuestion('nope'));
    expect(Decision::recorded())->toHaveCount(1);

    Decision::restore();
    expect(FluentDecision::resolveClient())->toBe($this->app->make(Client::class))
        ->and($this->app->make(Client::class)->engine()->name())->toBe('jev');
});

it('fakes injected clients and named engines, and still dispatches events', function (): void {
    Event::fake();
    Decision::fake(fn(DecisionRequest $r) => $r->state->value() === 'boom' ? new RuntimeException('resolver failure') : ['urgent' => Fake::noul(0.9)]);

    $client = $this->app->make(Client::class); // what constructor injection receives
    $jev = $client->for('x')->using('jev')->noul('urgent', 'Urgent?')->decide();
    $luna = $client->for('x')->using('luna')->noul('urgent', 'Urgent?')->decide();

    expect($jev->urgent->noul)->toBe(0.9)
        ->and($luna->urgent->noul)->toBe(0.9)
        ->and(fn() => $client->for('boom')->noul('urgent', 'Urgent?')->decide())->toThrow(RuntimeException::class, 'resolver failure');

    Decision::assertDecided(fn(DecisionRequest $r) => $r->engine === 'luna', times: 1);
    Decision::assertDecidedCount(3);
    Event::assertDispatched(Decided::class, 2);
    Event::assertDispatched(DecisionFailed::class, fn(DecisionFailed $e): bool => $e->exception->getMessage() === 'resolver failure');
});

it('exposes parallel, settle, forEach, fromArray and fromJson through the facade', function (): void {
    Decision::fake(fn(DecisionRequest $r) => ['spam' => Fake::noul($r->state->value() === 'buy now' ? 0.9 : 0.1)]);

    $outcomes = Decision::forEach(['a' => 'buy now', 'b' => 'hello'])->noul('spam', 'Spam?')->decide();
    expect($outcomes['a']->spam->isTrue(0.8))->toBeTrue()->and($outcomes['b']->spam->isTrue(0.8))->toBeFalse();

    $settled = Decision::settle(['x' => FluentDecision::for('buy now')->noul('spam', 'Spam?'), 'y' => fn() => throw new RuntimeException('nope')]);
    expect($settled['x']->spam->noul)->toBe(0.9)->and($settled['y'])->toBeInstanceOf(RuntimeException::class);

    $parallel = Decision::parallel(['x' => FluentDecision::for('buy now')->noul('spam', 'Spam?')]);
    expect($parallel['x']->spam->noul)->toBe(0.9);

    $array = Decision::fromArray($this->fixture('quickstart-request'));
    expect($array->questions()->ids())->toBe(['department', 'frustration', 'is_urgent'])
        ->and(Decision::fromJson($array->toJson())->toArray())->toBe($array->toArray());
});

it('runs the artisan commands', function (): void {
    Decision::fake(['department' => Fake::choice('technical', 0.8), 'frustration' => Fake::score(1.0), 'is_urgent' => Fake::noul(1.0)]);

    $file = tempnam(sys_get_temp_dir(), 'decision') . '.json';
    file_put_contents($file, json_encode($this->fixture('quickstart-request')));

    $this->artisan('decision-engine:try', ['file' => $file])
        ->expectsOutputToContain('technical')
        ->expectsOutputToContain('engine=fake')
        ->assertSuccessful();

    $this->artisan('decision-engine:try', ['file' => $file, '--json' => true])
        ->expectsOutputToContain('"choice": "technical"')
        ->assertSuccessful();

    $this->artisan('decision-engine:try', ['file' => $file, '--dry-run' => true])
        ->expectsOutputToContain('POST fake://decision')
        ->assertSuccessful();

    $this->artisan('decision-engine:try', ['file' => '/nonexistent.json'])->assertFailed();

    file_put_contents($file, '{"state": "x"}');
    $this->artisan('decision-engine:try', ['file' => $file])->expectsOutputToContain('questions')->assertFailed();

    unlink($file);

    // models: no network available in tests; the fake engine is not a jev driver → clear failure message
    $this->artisan('decision-engine:models')->expectsOutputToContain('models endpoint')->assertFailed();
});

it('publishes the config file', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'decision-engine-config', '--force' => true])->assertSuccessful();

    $published = $this->app->configPath('decision-engine.php');
    expect(is_file($published))->toBeTrue();
    unlink($published);
});
