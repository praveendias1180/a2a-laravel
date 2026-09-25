<?php

declare(strict_types=1);

namespace A2A\Laravel\Routing;

use A2A\Laravel\A2AManager;
use A2A\Laravel\AgentDefinition;
use A2A\Laravel\Http\A2AController;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * What Route::a2a() returns: the routes it registered for one agent.
 *
 * middleware() applies to the JSON-RPC and REST routes only. The Agent Card
 * stays public, as discovery requires.
 */
final class A2ARoutes
{
    /** CSRF middleware left off the protocol routes (clients are not browsers). */
    private const CSRF_MIDDLEWARE = [
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'App\Http\Middleware\VerifyCsrfToken',
    ];

    /**
     * @param list<Route> $protocolRoutes
     * @param list<Route> $cardRoutes
     */
    private function __construct(
        public readonly AgentDefinition $agent,
        public readonly array $protocolRoutes,
        public readonly array $cardRoutes,
    ) {}

    /**
     * Registers an agent's routes under $prefix:
     *
     * - GET  {prefix}/.well-known/agent-card.json (and /.well-known/agent-card.json when $wellKnown)
     * - POST {prefix}/jsonrpc
     * - GET|POST|DELETE {prefix}/rest/...
     *
     * @param string|array<array-key, mixed>|null $agentCard a card provider class, or the card as a ProtoJSON array
     * @param string|null                      $executor  an AgentExecutor class
     */
    public static function register(
        Router $router,
        A2AManager $manager,
        string $prefix = '/a2a',
        string|array|null $agentCard = null,
        ?string $executor = null,
        ?string $agent = null,
        ?string $runner = null,
        bool $wellKnown = true,
    ): self {
        $prefix = '/' . trim($prefix, '/');
        if ($agentCard === null && $executor === null) {
            $definition = $manager->agent($agent ?? 'default')->withPrefix($prefix);
        } elseif ($agentCard !== null && $executor !== null) {
            if (is_string($agentCard) && !class_exists($agentCard)) {
                throw new \InvalidArgumentException(sprintf('Agent card class %s does not exist.', $agentCard));
            }
            if (!class_exists($executor)) {
                throw new \InvalidArgumentException(sprintf('Executor class %s does not exist.', $executor));
            }
            $definition = new AgentDefinition($agent ?? 'default', $agentCard, $executor, $runner, prefix: $prefix);
        } else {
            throw new \InvalidArgumentException('Route::a2a() needs both agentCard and executor, or neither (to use a2a.agents config).');
        }
        if ($runner !== null && $definition->runner !== $runner) {
            $definition = new AgentDefinition($definition->name, $definition->card, $definition->executor, $runner, $definition->middleware, $definition->extendedCard, $definition->prefix);
        }

        $defaults = $definition->toArray();
        $base = $prefix === '/' ? '' : $prefix;
        $name = 'a2a.' . $definition->name . '.';

        $cardRoutes = [
            $router->get($base . '/.well-known/agent-card.json', [A2AController::class, 'card'])
                ->defaults(A2AController::AGENT_DEFAULT, $defaults)
                ->name($name . 'card'),
        ];
        if ($wellKnown && $base !== '') {
            $cardRoutes[] = $router->get('/.well-known/agent-card.json', [A2AController::class, 'card'])
                ->defaults(A2AController::AGENT_DEFAULT, $defaults)
                ->name($name . 'well-known');
        }

        $protocolRoutes = [
            $router->post($base . '/jsonrpc', [A2AController::class, 'jsonRpc'])
                ->defaults(A2AController::AGENT_DEFAULT, $defaults)
                ->name($name . 'jsonrpc'),
            $router->match(['GET', 'POST', 'DELETE'], $base . '/rest/{path}', [A2AController::class, 'rest'])
                ->where('path', '.*')
                ->defaults(A2AController::AGENT_DEFAULT, $defaults)
                ->name($name . 'rest'),
        ];

        $routes = new self($definition, $protocolRoutes, $cardRoutes);
        foreach ($protocolRoutes as $route) {
            $route->withoutMiddleware(self::CSRF_MIDDLEWARE);
        }
        $routes->middleware(...$definition->middleware);
        $routes->middleware(...self::securityMiddleware($manager, $definition));

        return $routes;
    }

    /**
     * Adds middleware to the JSON-RPC and REST routes (never to the card).
     */
    public function middleware(string ...$middleware): self
    {
        if ($middleware !== []) {
            foreach ($this->protocolRoutes as $route) {
                $route->middleware(array_values($middleware));
            }
        }

        return $this;
    }

    /**
     * Middleware for the security schemes the card requires, from
     * `a2a.security_schemes` (scheme name => middleware).
     *
     * @return list<string>
     */
    private static function securityMiddleware(A2AManager $manager, AgentDefinition $definition): array
    {
        $map = config('a2a.security_schemes', []);
        if (!is_array($map) || $map === []) {
            return [];
        }

        $middleware = [];
        foreach ($manager->card($definition)->getSecurityRequirements() as $requirement) {
            foreach ($requirement->getSchemes() as $scheme => $_scopes) {
                if (!is_string($scheme)) {
                    continue;
                }
                $mapped = $map[$scheme] ?? null;
                foreach (is_array($mapped) ? $mapped : [$mapped] as $entry) {
                    if (is_string($entry) && $entry !== '' && !in_array($entry, $middleware, true)) {
                        $middleware[] = $entry;
                    }
                }
            }
        }

        return $middleware;
    }
}
