<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Tests\Laravel;

use Orchestra\Testbench\TestCase as Testbench;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Laravel\DecisionEngineServiceProvider;

abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [DecisionEngineServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('decision-engine.engines.jev.api_key', 'sk-test');
        $app['config']->set('decision-engine.engines.luna.api_key', 'sk-openai');
        $app['config']->set('decision-engine.engines.luna-responses.api_key', 'sk-openai');
        $app['config']->set('decision-engine.engines.haiku.api_key', 'sk-anthropic');
    }

    protected function tearDown(): void
    {
        Decision::restore();
        Decision::resolveClientUsing(null);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
