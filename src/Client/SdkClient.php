<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Client;

use Calisero\Sms\Http\HttpClient;
use Calisero\Sms\Services\AccountService;
use Calisero\Sms\Services\MessageService;
use Calisero\Sms\Services\OptOutService;
use Calisero\Sms\Services\VerificationService;

/**
 * The Calisero SDK's services over an HttpClient of the bundle's making.
 *
 * Same accessors as \Calisero\Sms\SmsClient, whose constructor is private: its create()
 * fixes the base URI and the timeouts, so it cannot honour the bundle configuration.
 * Built by ClientFactory::make(); the container exposes it as calisero.sdk_client, and
 * its services as calisero.messages, calisero.verifications, calisero.opt_outs and
 * calisero.accounts (autowired by their class).
 */
final class SdkClient
{
    private readonly MessageService $messageService;

    private readonly OptOutService $optOutService;

    private readonly AccountService $accountService;

    private readonly VerificationService $verificationService;

    public function __construct(private readonly HttpClient $httpClient)
    {
        $this->messageService = new MessageService($httpClient);
        $this->optOutService = new OptOutService($httpClient);
        $this->accountService = new AccountService($httpClient);
        $this->verificationService = new VerificationService($httpClient);
    }

    public function messages(): MessageService
    {
        return $this->messageService;
    }

    public function optOuts(): OptOutService
    {
        return $this->optOutService;
    }

    public function accounts(): AccountService
    {
        return $this->accountService;
    }

    public function verifications(): VerificationService
    {
        return $this->verificationService;
    }

    /**
     * The SDK's HttpClient, whose getLastResponse() gives the raw answer to the last
     * request, headers included.
     */
    public function getHttpClient(): HttpClient
    {
        return $this->httpClient;
    }
}
