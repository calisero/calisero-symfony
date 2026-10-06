<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Client;

use Calisero\Sms\Contracts\HttpClientInterface;
use Calisero\Sms\Http\BaseHttpClient;
use Calisero\Sms\Http\Factory\HttpFactory;
use Calisero\Sms\Http\HttpClient;

/**
 * Builds the Calisero SDK's services on the bundle configuration: base URI, timeouts and
 * the bundle's User-Agent.
 *
 * The SDK's own SmsClient::create() cannot be used: it fixes the base URI, a 30 s timeout
 * and its User-Agent. Inject this factory (service calisero.client_factory) to build the
 * services on another API key, e.g. one per tenant.
 */
final class ClientFactory
{
    public const DEFAULT_BASE_URI = 'https://rest.calisero.ro/api/v1';

    public const DEFAULT_TIMEOUT = 10;

    public const DEFAULT_CONNECT_TIMEOUT = 3;

    public function __construct(
        private readonly HttpClientInterface $transport,
        private readonly ?string $baseUri = self::DEFAULT_BASE_URI,
    ) {
    }

    /**
     * The SDK's cURL transport, with the given timeouts in seconds. cURL takes whole
     * seconds: a fraction is rounded up, and never down to 0, which cURL reads as "no
     * timeout"; a value that is not a number falls back to the default.
     */
    public static function createTransport(mixed $timeout = self::DEFAULT_TIMEOUT, mixed $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT): BaseHttpClient
    {
        return new BaseHttpClient(
            self::seconds($timeout, self::DEFAULT_TIMEOUT),
            self::seconds($connectTimeout, self::DEFAULT_CONNECT_TIMEOUT),
        );
    }

    /**
     * The SDK's services on the given API key and base URI (the configured one when null),
     * sending the bundle's User-Agent.
     *
     * The key is checked when the first request is made, not here: a missing one fails
     * there with a \RuntimeException naming calisero.api_key, so an application without a
     * key still boots.
     */
    public function make(#[\SensitiveParameter] ?string $apiKey, ?string $baseUri = null): SdkClient
    {
        return new SdkClient(new HttpClient(
            new UserAgentHttpClient($this->transport, UserAgent::build()),
            new HttpFactory(),
            new ApiKeyAuthProvider($apiKey),
            self::baseUri($baseUri ?? $this->baseUri),
        ));
    }

    /**
     * The base URI with no trailing slash; the production one when none is given.
     */
    public function getBaseUri(): string
    {
        return self::baseUri($this->baseUri);
    }

    private static function baseUri(?string $baseUri): string
    {
        $baseUri = rtrim(trim((string) $baseUri), '/');

        return '' !== $baseUri ? $baseUri : self::DEFAULT_BASE_URI;
    }

    private static function seconds(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max(1, (int) ceil((float) $value));
    }
}
