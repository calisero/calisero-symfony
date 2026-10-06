<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Client;

use Calisero\Sms\Contracts\AuthProviderInterface;

/**
 * The bearer token of every request: the API key, checked when a request is made rather
 * than when the container builds the client, so an application without a key still boots
 * and fails only where it calls the API.
 *
 * @internal
 */
final class ApiKeyAuthProvider implements AuthProviderInterface
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly ?string $apiKey,
    ) {
    }

    public function getToken(): string
    {
        if (null === $this->apiKey || '' === trim($this->apiKey)) {
            throw new \RuntimeException('Calisero API key is not configured (calisero.api_key)');
        }

        return $this->apiKey;
    }
}
