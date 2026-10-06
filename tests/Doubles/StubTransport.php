<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Doubles;

use Calisero\Sms\Contracts\HttpClientInterface;
use Calisero\Sms\Http\ClientException;
use Calisero\Sms\Http\RequestInterface;
use Calisero\Sms\Http\Response;
use Calisero\Sms\Http\ResponseInterface;

/**
 * Stands in for the SDK's cURL transport: answers each request with the next queued
 * response and records the request, as it would have gone over the wire.
 */
final class StubTransport implements HttpClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $responses = [];

    /**
     * Queue a JSON answer.
     *
     * @param array<array-key, mixed> $body
     * @param array<string, string>   $headers
     */
    public function respond(int $status, array $body = [], array $headers = []): self
    {
        $headerValues = ['Content-Type' => ['application/json']];

        foreach ($headers as $name => $value) {
            $headerValues[$name] = [$value];
        }

        $this->responses[] = new Response($status, $headerValues, [] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this;
    }

    /**
     * Queue a request that gets no answer: a network error or a timeout.
     */
    public function fail(string $message = 'Connection timed out'): self
    {
        $this->responses[] = new ClientException('cURL error: '.$message);

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses)
            ?? throw new \LogicException(\sprintf('No response queued for %s %s.', $request->getMethod(), $request->getUri()));

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new \LogicException('No request was sent.')];
    }

    /**
     * The JSON body of the last request, decoded.
     *
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        $payload = json_decode($this->lastRequest()->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($payload));

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * The value of a header of the last request.
     */
    public function lastHeader(string $name): ?string
    {
        return $this->lastRequest()->getHeaders()[$name][0] ?? null;
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->responses = [];
    }
}
