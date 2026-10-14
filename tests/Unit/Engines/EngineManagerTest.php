<?php

declare(strict_types=1);

use Swis\DecisionEngine\Config;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Engines\Capabilities;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Engines\Jev\JevEngine;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Transport\HttpResponse;

function baseConfig(): array
{
    return [
        'default' => 'jev',
        'engines' => [
            'jev' => ['driver' => 'jev', 'api_key' => 'k', 'model' => 'jev-1.13.0'],
            'alias' => ['driver' => 'jev', 'api_key' => 'k2', 'base_url' => 'https://example.test'],
            'broken' => ['driver' => 'nope', 'api_key' => 'k'],
        ],
        'thresholds' => ['high' => 0.95, 'medium' => 0.6],
    ];
}

it('resolves the default and named engines and caches instances', function (): void {
    $manager = EngineManager::fromArray(baseConfig());

    expect($manager->default())->toBe('jev')
        ->and($manager->engine())->toBeInstanceOf(JevEngine::class)
        ->and($manager->engine()->defaultModel())->toBe('jev-1.13.0')
        ->and($manager->engine())->toBe($manager->engine('jev'))
        ->and($manager->engine('alias'))->toBeInstanceOf(JevEngine::class)
        ->and($manager->engine('alias'))->not->toBe($manager->engine('jev'))
        ->and($manager->has('alias'))->toBeTrue()
        ->and($manager->has('zzz'))->toBeFalse()
        ->and($manager->names())->toBe(['jev', 'alias', 'broken'])
        ->and($manager->drivers())->toContain('jev')
        ->and($manager->thresholds()->high)->toBe(0.95);
});

it('throws configuration errors with the offending key', function (): void {
    $manager = EngineManager::fromArray(baseConfig());

    try {
        $manager->engine('missing');
        $this->fail('expected exception');
    } catch (ConfigurationException $e) {
        expect($e->key)->toBe('engines.missing');
    }

    try {
        $manager->engine('broken');
        $this->fail('expected exception');
    } catch (ConfigurationException $e) {
        expect($e->key)->toBe('engines.broken.driver');
    }

    expect(fn() => EngineManager::fromArray(['engines' => ['x' => 'str']]))->toThrow(ConfigurationException::class)
        ->and(fn() => (new EngineManager(['x' => ['driver' => 1]]))->engine('x'))->toThrow(ConfigurationException::class);
});

it('supports extend(), register() and configure()', function (): void {
    $manager = EngineManager::fromArray(baseConfig());

    $custom = new class implements Engine {
        public function name(): string
        {
            return 'custom';
        }

        public function defaultModel(): string
        {
            return 'c-1';
        }

        public function capabilities(): Capabilities
        {
            return Capabilities::llm();
        }

        public function prepare(DecisionRequest $request): PreparedRequest
        {
            return new PreparedRequest('POST', 'fake://x', [], '');
        }

        public function interpret(DecisionRequest $request, HttpResponse $response): Outcome
        {
            throw new LogicException('not used');
        }
    };

    $manager->extend('custom', fn(array $config, string $name): Engine => $custom);
    $manager->configure('mine', ['driver' => 'custom', 'anything' => true]);
    $manager->register('inst', $custom);

    expect($manager->engine('mine'))->toBe($custom)
        ->and($manager->engine('custom'))->toBe($custom) // bare driver name works when unconfigured
        ->and($manager->engine('inst'))->toBe($custom)
        ->and($manager->setDefault('mine')->engine())->toBe($custom)
        ->and($manager->names())->toContain('inst');
});

it('builds a typed Config from the shipped file and env', function (): void {
    $config = Config::fromArray(require __DIR__ . '/../../../config/decision-engine.php');

    expect($config->defaultEngine)->toBe('jev')
        ->and(array_keys($config->engines))->toBe(['jev', 'luna', 'luna-responses', 'haiku'])
        ->and($config->engines['luna']['driver'])->toBe('openai')
        ->and($config->engines['luna-responses']['driver'])->toBe('openai_responses')
        ->and($config->retry->maxRetries)->toBe(2)
        ->and($config->retry->retriesStatus(503))->toBeTrue()
        ->and($config->concurrency)->toBe(10)
        ->and($config->thresholds->high)->toBe(0.9)
        ->and($config->transportDriver())->toBe('curl')
        ->and($config->timeout())->toBe(10.0)
        ->and($config->connectTimeout())->toBe(5.0)
        ->and($config->engineConfig('haiku')['model'])->toBe('claude-haiku-4-5')
        ->and(Config::fromArray($config->toArray())->toArray())->toBe($config->toArray())
        ->and(fn() => $config->engineConfig('zzz'))->toThrow(ConfigurationException::class)
        ->and(fn() => new Config(concurrency: 0))->toThrow(ConfigurationException::class);

    // A local .env (loaded by tests/Pest.php) also fills $_SERVER, which Laravel's env() reads first.
    $saved = [$_ENV, $_SERVER];
    $_ENV['TYPESAFE_API_KEY'] = $_SERVER['TYPESAFE_API_KEY'] = 'env-key';
    $_ENV['TYPESAFE_DEFAULT_MODEL'] = $_SERVER['TYPESAFE_DEFAULT_MODEL'] = 'jev-1.13.0';
    $env = Config::fromEnv();
    [$_ENV, $_SERVER] = $saved;

    expect($env->engines['jev']['api_key'])->toBe('env-key')
        ->and($env->engines()->engine()->defaultModel())->toBe('jev-1.13.0');
});
