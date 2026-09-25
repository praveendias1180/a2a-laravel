<?php

declare(strict_types=1);

namespace A2A\Laravel\Http;

use A2A\Auth\User;
use A2A\Server\Routes\DefaultServerCallContextBuilder;
use A2A\Server\Routes\ResponseEmitter;
use A2A\Server\Routes\Sse\SseStream;
use Http\Discovery\Psr17Factory;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Converts between Laravel's requests/responses and the PSR-7 messages the
 * core SDK's dispatchers speak.
 *
 * SSE bodies become a StreamedResponse that writes each event as the agent
 * publishes it, through the same ResponseEmitter::streamSse() the plain-PHP
 * server uses.
 *
 * @internal
 */
final class PsrBridge
{
    public static function toPsr(Request $request, ?User $user = null): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $server = [];
        foreach ($request->server->all() as $key => $value) {
            $server[$key] = $value;
        }
        $psr = $factory->createServerRequest($request->getMethod(), $request->getUri(), $server);
        foreach ($request->headers->all() as $name => $values) {
            $psr = $psr->withHeader((string) $name, array_values(array_filter($values, 'is_string')));
        }
        $psr = $psr
            ->withBody($factory->createStream($request->getContent()))
            ->withQueryParams($request->query->all());
        if ($user !== null) {
            $psr = $psr->withAttribute(DefaultServerCallContextBuilder::USER_ATTRIBUTE, $user);
        }

        return $psr;
    }

    public static function toLaravel(ResponseInterface $response, bool $endOutputBuffers = true): Response
    {
        $headers = $response->getHeaders();
        $body = $response->getBody();

        if ($body instanceof SseStream) {
            return new StreamedResponse(
                static function () use ($body, $endOutputBuffers): void {
                    ResponseEmitter::streamSse($body, $endOutputBuffers);
                },
                $response->getStatusCode(),
                $headers,
            );
        }

        return new Response((string) $body, $response->getStatusCode(), $headers);
    }
}
