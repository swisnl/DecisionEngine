<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Config;
use Swis\DecisionEngine\Contracts\Transport;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Laravel\Collections\CollectionMacros;
use Swis\DecisionEngine\Laravel\Console\ModelsCommand;
use Swis\DecisionEngine\Laravel\Console\TryCommand;
use Swis\DecisionEngine\Laravel\Events\Decided;
use Swis\DecisionEngine\Laravel\Events\DecisionFailed;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Transport\CurlTransport;

/**
 * Laravel bridge: binds `Config`, `EngineManager`, `Transport` and `Client` as singletons, points
 * the static `Decision::…` entry points at the container, publishes the config file, registers the
 * artisan commands, dispatches `Decided` / `DecisionFailed` events and registers the collection
 * macros (`whereNoul`, `sortByScore`, `groupByChoice`, …; see `CollectionMacros`).
 *
 * ```bash
 * php artisan vendor:publish --tag=decision-engine-config
 * php artisan decision-engine:models
 * php artisan decision-engine:try storage/decisions/triage.json --engine=haiku
 * ```
 */
final class DecisionEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/decision-engine.php', 'decision-engine');

        $this->app->singleton(Config::class, static function (Application $app): Config {
            /** @var array<string, mixed> $config */
            $config = $app->make(ConfigRepository::class)->get('decision-engine', []);

            return Config::fromArray($config);
        });

        $this->app->singleton(EngineManager::class, static fn(Application $app): EngineManager => $app->make(Config::class)->engines());

        $this->app->singleton(Transport::class, static function (Application $app): Transport {
            $config = $app->make(Config::class);

            return match ($config->transportDriver()) {
                'laravel', 'http' => new LaravelHttpTransport($app->make(HttpFactory::class), $config->timeout(), $config->connectTimeout()),
                default => CurlTransport::fromConfig($config->transport),
            };
        });

        $this->app->singleton(Client::class, function (Application $app): Client {
            $config = $app->make(Config::class);

            $client = new Client(
                $app->make(EngineManager::class),
                $app->make(Transport::class),
                new RequestOptions(timeout: $config->timeout(), connectTimeout: $config->connectTimeout()),
                $config->retry,
                $this->logger($app, $config),
                $config->concurrency,
            );

            $events = $app->make(Dispatcher::class);

            $client->after(static function (DecisionRequest $request, Outcome $outcome) use ($events): void {
                $events->dispatch(new Decided($request, $outcome));
            })->onFailure(static function (DecisionRequest $request, \Throwable $e) use ($events): void {
                $events->dispatch(new DecisionFailed($request, $e));
            });

            return $client;
        });

        Decision::resolveClientUsing(fn(): Client => $this->app->make(Client::class));
    }

    public function boot(): void
    {
        CollectionMacros::register();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/decision-engine.php' => $this->app->configPath('decision-engine.php')], 'decision-engine-config');
            $this->commands([ModelsCommand::class, TryCommand::class]);
        }
    }

    private function logger(Application $app, Config $config): LoggerInterface
    {
        $log = $app->make('log');
        $channel = $config->logging['channel'] ?? null;

        if ($log instanceof LogManager && is_string($channel) && $channel !== '') {
            return $log->channel($channel);
        }

        return $log instanceof LoggerInterface ? $log : $app->make(LoggerInterface::class);
    }
}
