<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Support;

use Http\Discovery\Psr17Factory;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A PSR-18 client that sends requests straight into the Laravel app under
 * test, so SDK clients can talk to Route::a2a() routes without a server.
 * Streamed (SSE) responses are collected whole.
 */
final class InProcessPsr18Client implements ClientInterface
{
    public function __construct(private readonly Kernel $kernel) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $server = [];
        foreach ($request->getHeaders() as $name => $values) {
            $key = strtoupper(str_replace('-', '_', (string) $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = implode(', ', $values);
        }
        $laravelRequest = Request::create((string) $request->getUri(), $request->getMethod(), [], [], [], $server, (string) $request->getBody());
        $response = $this->kernel->handle($laravelRequest);

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
        } else {
            $body = (string) $response->getContent();
        }
        $this->kernel->terminate($laravelRequest, $response);

        $factory = new Psr17Factory();
        $psr = $factory->createResponse($response->getStatusCode());
        foreach ($response->headers->all() as $name => $values) {
            $psr = $psr->withHeader((string) $name, array_values(array_filter($values, 'is_string')));
        }

        return $psr->withBody($factory->createStream($body));
    }
}
