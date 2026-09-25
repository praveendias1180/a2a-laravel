<?php

declare(strict_types=1);

namespace A2A\Laravel\Contracts;

use A2A\Types\AgentCard;

/**
 * Builds an agent's card. Resolved from the container, so it can take
 * dependencies (config, a translator, ...).
 *
 * Leave `supported_interfaces` empty to have the bridge fill them in from
 * the routes Route::a2a() registered.
 */
interface AgentCardProvider
{
    public function agentCard(): AgentCard;
}
