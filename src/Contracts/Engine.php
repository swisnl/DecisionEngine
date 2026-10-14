<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Contracts;

use Swis\DecisionEngine\Engines\Capabilities;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * Turns a DecisionRequest into an HTTP request and an HTTP response into an Outcome.
 * Engines are pure: they never perform I/O, so one scheduler can drive many engines concurrently
 * and tests can exercise them without a network.
 *
 * ```php
 * $prepared = $engine->prepare($request);              // PreparedRequest — nothing sent yet
 * $response = $transport->send($prepared, $options);   // HttpResponse
 * $outcome  = $engine->interpret($request, $response); // Outcome, or an ApiException subclass
 * ```
 */
interface Engine
{
    /**
     * Driver name: `jev`, `openai`, `openai_responses`, `anthropic` or a custom one.
     */
    public function name(): string;

    public function defaultModel(): string;

    public function capabilities(): Capabilities;

    /**
     * Render the request for this engine's API. Must not perform I/O.
     */
    public function prepare(DecisionRequest $request): PreparedRequest;

    /**
     * Turn the response into an Outcome.
     *
     * @throws \Swis\DecisionEngine\Exceptions\ApiException on a non-2xx status (see the subclasses)
     * @throws \Swis\DecisionEngine\Exceptions\ResponseValidationException on a 2xx with an invalid body
     */
    public function interpret(DecisionRequest $request, HttpResponse $response): Outcome;
}
