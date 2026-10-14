<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel;

use Swis\DecisionEngine\Decision;

/**
 * Start a decision about this object, with `toDecisionState()` as the state.
 *
 * ```php
 * $ticket->decision()->noul('urgent', 'Is this urgent?')->decide();
 * ```
 *
 * @phpstan-require-implements ProvidesDecisionState
 */
trait HasDecisions
{
    public function decision(): Decision
    {
        return Decision::for($this->toDecisionState());
    }
}
