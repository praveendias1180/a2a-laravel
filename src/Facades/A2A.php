<?php

declare(strict_types=1);

namespace A2A\Laravel\Facades;

use A2A\Laravel\A2AManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \A2A\Client\Client client(string|\A2A\Types\AgentCard $agent, ?\A2A\Client\ClientConfig $config = null, list<\A2A\Client\ClientCallInterceptor> $interceptors = [])
 * @method static \A2A\Laravel\AgentDefinition agent(string $name)
 * @method static \A2A\Types\AgentCard card(\A2A\Laravel\AgentDefinition $agent, ?string $baseUrl = null)
 * @method static \A2A\Server\RequestHandlers\DefaultRequestHandler handler(\A2A\Laravel\AgentDefinition $agent, ?string $baseUrl = null)
 * @method static \A2A\Server\Tasks\TaskStore taskStore()
 * @method static \A2A\Server\Events\QueueManager queueManager()
 *
 * @see A2AManager
 */
final class A2A extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return A2AManager::class;
    }
}
