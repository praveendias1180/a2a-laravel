<?php

declare(strict_types=1);

namespace A2A\Laravel\Http;

use A2A\Laravel\A2AManager;
use A2A\Laravel\AgentDefinition;
use A2A\Laravel\Auth\LaravelUser;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\Routes;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the routes Route::a2a() registers by handing each request to the
 * core SDK's PSR-15 handlers (AgentCardHandler, JsonRpcDispatcher,
 * RestDispatcher). All protocol behaviour lives in the core.
 *
 * @internal
 */
final class A2AController
{
    public const AGENT_DEFAULT = '_a2a';

    public function __construct(
        private readonly A2AManager $manager,
        private readonly Application $app,
    ) {}

    public function card(Request $request): Response
    {
        $agent = $this->agent($request);

        return $this->serve($request, Routes::agentCard($this->manager->card($agent, $this->baseUrl($agent)), signer: $this->manager->cardSigner()));
    }

    public function jsonRpc(Request $request): Response
    {
        $agent = $this->agent($request);
        $handler = $this->handler($agent);

        return $this->serve($request, Routes::jsonRpc($handler, logger: $this->manager->logger()));
    }

    public function rest(Request $request): Response
    {
        $agent = $this->agent($request);
        $handler = $this->handler($agent);
        $prefix = rtrim($request->getBaseUrl() . '/' . trim($agent->prefix, '/'), '/') . '/rest';

        return $this->serve($request, Routes::rest($handler, str_replace('//', '/', $prefix), logger: $this->manager->logger()));
    }

    private function handler(AgentDefinition $agent): DefaultRequestHandler
    {
        $handler = $this->manager->handler($agent, $this->baseUrl($agent));
        // Work that continues after the response (e.g. `returnImmediately`
        // with the inline runner) runs once Laravel has sent it.
        $this->app->terminating(static fn() => $handler->runBackgroundWork());

        return $handler;
    }

    private function serve(Request $request, RequestHandlerInterface $handler): Response
    {
        $guard = config('a2a.guard');
        $user = $request->user(is_string($guard) ? $guard : null);
        $psr = PsrBridge::toPsr($request, $user instanceof \Illuminate\Contracts\Auth\Authenticatable ? new LaravelUser($user) : null);

        return PsrBridge::toLaravel($handler->handle($psr), endOutputBuffers: !$this->app->runningUnitTests());
    }

    private function agent(Request $request): AgentDefinition
    {
        $data = $request->route()->defaults[self::AGENT_DEFAULT] ?? null;
        if (!is_array($data)) {
            throw new \LogicException('This route was not registered with Route::a2a().');
        }

        return AgentDefinition::fromArray($data);
    }

    private function baseUrl(AgentDefinition $agent): string
    {
        return url(trim($agent->prefix, '/'));
    }
}
