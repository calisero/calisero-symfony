<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Client;

use Calisero\Sms\Contracts\HttpClientInterface;
use Calisero\Sms\Http\RequestInterface;
use Calisero\Sms\Http\ResponseInterface;

/**
 * Sends every request with the bundle's User-Agent.
 *
 * The SDK's HttpClient sets its own (Calisero-SMS-PHP/<version>) just before it hands
 * the request to its transport: wrapping the transport is the last point where the
 * header can still be replaced.
 *
 * @internal
 */
final class UserAgentHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $userAgent,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request->withHeader('User-Agent', $this->userAgent));
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }
}
