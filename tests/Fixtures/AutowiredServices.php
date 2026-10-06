<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Fixtures;

use Calisero\Sms\Services\AccountService;
use Calisero\Sms\Services\MessageService;
use Calisero\Sms\Services\OptOutService;
use Calisero\Sms\Services\VerificationService;
use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\Client\SdkClient;
use Calisero\SymfonySms\SmsClientInterface;

/**
 * An application service that autowires everything the bundle offers, as an
 * application's own services would.
 */
final class AutowiredServices
{
    public function __construct(
        public readonly SmsClientInterface $smsClient,
        public readonly SdkClient $sdkClient,
        public readonly ClientFactory $clientFactory,
        public readonly MessageService $messages,
        public readonly VerificationService $verifications,
        public readonly OptOutService $optOuts,
        public readonly AccountService $accounts,
    ) {
    }
}
