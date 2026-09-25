<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Tests\TestCase;
use Illuminate\Routing\Router;

final class AgentCardRoutesTest extends TestCase
{
    public function testTheCardIsServedAtTheWellKnownPathsWithInterfacesFromTheRoutes(): void
    {
        foreach (['/.well-known/agent-card.json', '/a2a/.well-known/agent-card.json'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/json');
            $response->assertJsonPath('name', 'Laravel Test Agent');
            $response->assertJsonPath('supportedInterfaces.0.url', 'http://localhost/a2a/jsonrpc');
            $response->assertJsonPath('supportedInterfaces.0.protocolBinding', 'JSONRPC');
            $response->assertJsonPath('supportedInterfaces.1.url', 'http://localhost/a2a/rest');
            $response->assertJsonPath('supportedInterfaces.1.protocolBinding', 'HTTP+JSON');
            self::assertStringContainsString('max-age=', (string) $response->headers->get('Cache-Control'));
            self::assertNotEmpty($response->headers->get('ETag'));
        }
    }

    public function testAMatchingEtagGets304(): void
    {
        $etag = (string) $this->get('/.well-known/agent-card.json')->headers->get('ETag');

        $this->get('/.well-known/agent-card.json', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function testRoutesAreNamedAndOnlyTheProtocolRoutesGetMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->a2a('/secure', agentCard: \A2A\Laravel\Tests\Fixtures\TestAgentCard::class, executor: \A2A\Laravel\Tests\Fixtures\EchoExecutor::class, agent: 'secure', wellKnown: false)
            ->middleware('auth');

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();
        self::assertContains('auth', $routes->getByName('a2a.secure.jsonrpc')?->middleware() ?? []);
        self::assertContains('auth', $routes->getByName('a2a.secure.rest')?->middleware() ?? []);
        self::assertNotContains('auth', $routes->getByName('a2a.secure.card')?->middleware() ?? []);
        self::assertNull($routes->getByName('a2a.secure.well-known'));
    }

    public function testSecuritySchemesInTheCardMapToMiddleware(): void
    {
        config()->set('a2a.security_schemes', ['bearer' => 'auth:api']);
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->a2a('/guarded', agentCard: [
            'name' => 'Guarded',
            'description' => 'Needs a token.',
            'version' => '1.0.0',
            'capabilities' => ['streaming' => true],
            'defaultInputModes' => ['text/plain'],
            'defaultOutputModes' => ['text/plain'],
            'skills' => [['id' => 's', 'name' => 'S', 'description' => 'S', 'tags' => ['t']]],
            'securitySchemes' => ['bearer' => ['httpAuthSecurityScheme' => ['scheme' => 'Bearer']]],
            'securityRequirements' => [['schemes' => ['bearer' => ['list' => []]]]],
        ], executor: \A2A\Laravel\Tests\Fixtures\EchoExecutor::class, agent: 'guarded', wellKnown: false);

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();
        self::assertContains('auth:api', $routes->getByName('a2a.guarded.jsonrpc')?->middleware() ?? []);
        self::assertNotContains('auth:api', $routes->getByName('a2a.guarded.card')?->middleware() ?? []);
    }

    public function testAgentsCanComeFromConfig(): void
    {
        config()->set('a2a.agents.configured', [
            'card' => \A2A\Laravel\Tests\Fixtures\TestAgentCard::class,
            'executor' => \A2A\Laravel\Tests\Fixtures\EchoExecutor::class,
        ]);
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->a2a('/configured', agent: 'configured', wellKnown: false);

        $this->get('/configured/.well-known/agent-card.json')
            ->assertOk()
            ->assertJsonPath('supportedInterfaces.0.url', 'http://localhost/configured/jsonrpc');
    }

    public function testTheRoutesSurviveRouteCaching(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        foreach (['card', 'well-known', 'jsonrpc', 'rest'] as $suffix) {
            $route = $routes->getByName('a2a.default.' . $suffix);
            self::assertNotNull($route);
            // What `php artisan route:cache` does with every route.
            $route->prepareForSerialization();
            $restored = unserialize(serialize($route));
            self::assertInstanceOf(\Illuminate\Routing\Route::class, $restored);
            self::assertStringContainsString('EchoExecutor', serialize($restored->defaults));
        }
    }
}
