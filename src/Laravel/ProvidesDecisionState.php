<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel;

/**
 * The state sent when this object is decided on, by the collection macros and `HasDecisions`.
 * Return only the fields the questions need.
 *
 * ```php
 * class Ticket extends Model implements ProvidesDecisionState
 * {
 *     use HasDecisions;
 *
 *     public function toDecisionState(): mixed
 *     {
 *         return ['subject' => $this->subject, 'message' => $this->body];
 *     }
 * }
 * ```
 */
interface ProvidesDecisionState
{
    public function toDecisionState(): mixed;
}
