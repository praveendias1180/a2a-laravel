<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Fixtures;

use A2A\Laravel\Contracts\AgentCardProvider;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentSkill;

final class TestAgentCard implements AgentCardProvider
{
    public function agentCard(): AgentCard
    {
        return new AgentCard([
            'name' => 'Laravel Test Agent',
            'description' => 'Echoes what it is told.',
            'version' => '1.0.0',
            'capabilities' => new AgentCapabilities(['streaming' => true, 'push_notifications' => true]),
            'default_input_modes' => ['text/plain'],
            'default_output_modes' => ['text/plain'],
            'skills' => [new AgentSkill(['id' => 'echo', 'name' => 'Echo', 'description' => 'Echoes input.', 'tags' => ['test']])],
        ]);
    }
}
