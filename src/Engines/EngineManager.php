<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines;

use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Engines\Anthropic\AnthropicEngine;
use Swis\DecisionEngine\Engines\Jev\JevEngine;
use Swis\DecisionEngine\Engines\OpenAi\OpenAiEngine;
use Swis\DecisionEngine\Engines\OpenAi\OpenAiResponsesEngine;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Support\Arr;

/**
 * Resolves named engines from configuration, Laravel-Manager style. Built-in drivers: `jev`,
 * `openai` (Decisions API), `openai_responses` (Responses API) and `anthropic` (see
 * config/decision-engine.php for the `luna`, `luna-responses` and `haiku` named engines). A *named*
 * engine (`luna`, `haiku`, …) picks a `driver` and hands the rest of its config to that driver's factory.
 *
 * ```php
 * $engines = EngineManager::fromArray(require 'config/decision-engine.php');
 * $engines->engine();          // default engine (jev)
 * $engines->engine('haiku');   // anthropic driver with the haiku config
 * $engines->extend('mock', fn (array $config, string $name): Engine => new MyEngine($config));
 * ```
 */
final class EngineManager
{
    /**
     * @var array<string, \Closure(array<string, mixed>, string): Engine>
     */
    private array $drivers = [];

    /**
     * @var array<string, Engine>
     */
    private array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>  $engines  name → config (with `driver`)
     */
    public function __construct(
        private array $engines,
        private string $default = JevEngine::NAME,
        private readonly Thresholds $thresholds = new Thresholds(),
    ) {
        $this->drivers[JevEngine::NAME] = fn(array $config): Engine => JevEngine::fromConfig(Arr::stringKeys($config), $this->thresholds);
        $this->drivers[OpenAiEngine::NAME] = fn(array $config): Engine => OpenAiEngine::fromConfig(Arr::stringKeys($config), $this->thresholds);
        $this->drivers[OpenAiResponsesEngine::NAME] = fn(array $config): Engine => OpenAiResponsesEngine::fromConfig(Arr::stringKeys($config), $this->thresholds);
        $this->drivers[AnthropicEngine::NAME] = fn(array $config): Engine => AnthropicEngine::fromConfig(Arr::stringKeys($config), $this->thresholds);
    }

    /**
     * Build from the full config array (`default`, `engines`, `thresholds`).
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $engines = [];

        foreach (Arr::array($config, 'engines') ?? [] as $name => $engineConfig) {
            if (! is_array($engineConfig)) {
                throw ConfigurationException::invalid("engines.{$name}", 'expected an array');
            }

            $engines[(string) $name] = Arr::stringKeys($engineConfig);
        }

        return new self(
            $engines,
            Arr::string($config, 'default') ?? JevEngine::NAME,
            Thresholds::fromArray(Arr::stringKeys(Arr::array($config, 'thresholds') ?? [])),
        );
    }

    /**
     * Register (or override) a driver factory. The closure receives the engine's config array and its name.
     *
     * @param  \Closure(array<string, mixed>, string): Engine  $factory
     */
    public function extend(string $driver, \Closure $factory): self
    {
        $this->drivers[$driver] = $factory;
        $this->resolved = [];

        return $this;
    }

    /**
     * Register a ready-made engine instance under a name.
     */
    public function register(string $name, Engine $engine): self
    {
        $this->resolved[$name] = $engine;
        $this->engines[$name] = ['driver' => $engine->name()];

        return $this;
    }

    /**
     * Add or replace a named engine's configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public function configure(string $name, array $config): self
    {
        $this->engines[$name] = $config;
        unset($this->resolved[$name]);

        return $this;
    }

    public function engine(?string $name = null): Engine
    {
        $name ??= $this->default;

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $config = $this->engines[$name] ?? null;

        if ($config === null) {
            // Allow using a driver name directly when no named engine shadows it (e.g. 'openai').
            if (isset($this->drivers[$name])) {
                $config = ['driver' => $name];
            } else {
                throw ConfigurationException::unknownEngine($name);
            }
        }

        $driver = $config['driver'] ?? $name;

        if (! is_string($driver)) {
            throw ConfigurationException::invalid("engines.{$name}.driver", 'expected a string');
        }

        $factory = $this->drivers[$driver] ?? throw ConfigurationException::unknownDriver($driver, $name);

        return $this->resolved[$name] = $factory(Arr::except($config, ['driver']), $name);
    }

    public function has(string $name): bool
    {
        return isset($this->engines[$name]) || isset($this->resolved[$name]) || isset($this->drivers[$name]);
    }

    public function default(): string
    {
        return $this->default;
    }

    public function setDefault(string $name): self
    {
        $this->default = $name;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([...array_keys($this->engines), ...array_keys($this->resolved)]));
    }

    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return array_keys($this->drivers);
    }

    public function thresholds(): Thresholds
    {
        return $this->thresholds;
    }
}
