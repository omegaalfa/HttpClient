<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http\Testing;

use Omegaalfa\HttpClient\Http\ConnectionPool;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Request;
use Omegaalfa\HttpClient\Http\RequestOptions;
use Omegaalfa\HttpClient\Http\Response;
use Omegaalfa\HttpClient\Http\StreamResponse;
use Omegaalfa\HttpClient\Http\TransportInterface;
use Omegaalfa\HttpClient\Loop\Loop;
use RuntimeException;
use Throwable;

/**
 * Transporte de teste para consumidores da biblioteca: devolve respostas
 * enfileiradas sem rede e registra as requisições recebidas.
 *
 * ```php
 * $transport = new MockTransport([
 *     new Response(200, new Headers(['Content-Type' => 'application/json']), '{"ok":true}'),
 * ]);
 *
 * $client = new AsyncHttpClient(transport: $transport);
 * $response = await($client->get('https://api.example.com/x'));
 * ```
 */
final class MockTransport implements TransportInterface
{
    /**
     * @var list<Response|StreamResponse|Throwable|callable>
     */
    private array $queue = [];

    /**
     * @var list<Request>
     */
    private array $requests = [];

    /**
     * @param iterable<Response|StreamResponse|Throwable|callable> $responses
     */
    public function __construct(iterable $responses = [])
    {
        foreach ($responses as $response) {
            $this->push($response);
        }

    }

    public function push(Response|StreamResponse|Throwable|callable $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function send(Request $request, RequestOptions $options): Response
    {
        $result = $this->next($request, $options);

        if (!$result instanceof Response) {
            throw new RuntimeException('MockTransport: send() requer uma Response enfileirada');
        }

        return $result;
    }

    public function sendStream(Request $request, RequestOptions $options): StreamResponse
    {
        $result = $this->next($request, $options);

        if (!$result instanceof StreamResponse) {
            throw new RuntimeException('MockTransport: sendStream() requer uma StreamResponse enfileirada (use MockTransport::stream())');
        }

        return $result;
    }

    /**
     * Requisições recebidas, em ordem — inclusive tentativas de retry.
     *
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?Request
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    public function pendingCount(): int
    {
        return count($this->queue);
    }

    /**
     * Cria uma StreamResponse de teste a partir de um corpo pronto.
     *
     * @param array<string, string|list<string>>|Headers $headers
     */
    public static function stream(
        string $body,
        int $status = 200,
        array|Headers $headers = [],
        ?Loop $loop = null
    ): StreamResponse {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('MockTransport could not create a socket pair');
        }

        [$read, $write] = $sockets;
        fwrite($write, $body);
        fclose($write);

        return new StreamResponse(
            $loop ?? Loop::get(),
            new ConnectionPool(),
            $read,
            new RequestOptions(),
            'mock',
            false,
            $status,
            Headers::from($headers),
            [],
            false,
            '',
            false,
            strlen($body)
        );
    }

    private function next(Request $request, RequestOptions $options): Response|StreamResponse
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('MockTransport has no more queued responses');
        }

        $result = array_shift($this->queue);

        if (is_callable($result)) {
            $result = $result($request, $options);
        }

        if ($result instanceof Throwable) {
            throw $result;
        }

        if (!$result instanceof Response && !$result instanceof StreamResponse) {
            throw new RuntimeException('MockTransport queued value must be a Response, StreamResponse, Throwable or callable');
        }

        return $result;
    }
}
