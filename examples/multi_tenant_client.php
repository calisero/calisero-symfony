<?php

declare(strict_types=1);

/*
 * Example: one API key per tenant
 *
 * The autowired services use calisero.api_key. ClientFactory builds the SDK's services on
 * another key, with the same base URI, timeouts and User-Agent; SmsClient wraps them for
 * the same methods as SmsClientInterface.
 */

namespace App\Sms;

use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\SmsClient;
use Calisero\SymfonySms\SmsClientInterface;

final class TenantSmsClients
{
    /** @var array<string, SmsClientInterface> */
    private array $clients = [];

    public function __construct(
        private readonly ClientFactory $factory,
    ) {
    }

    public function for(string $tenantId, string $apiKey, ?string $accountId = null): SmsClientInterface
    {
        // No webhook callback here: pass the CallbackUrlGenerator (service
        // calisero.webhook.callback_url_generator) as the second argument for one
        return $this->clients[$tenantId] ??= new SmsClient($this->factory->make($apiKey), null, $accountId);
    }
}
