<?php

declare(strict_types=1);

/*
 * Example: the SDK's services, autowired
 *
 * SmsClientInterface covers what an application needs most. For everything else (the
 * opt-outs, the list of verifications...) the Calisero PHP SDK's services are autowired,
 * built on the bundle configuration: base URI, timeouts, User-Agent.
 */

namespace App\Sms;

use Calisero\Sms\Dto\CreateOptOutRequest;
use Calisero\Sms\Exceptions\ForbiddenException;
use Calisero\Sms\Exceptions\NotFoundException;
use Calisero\Sms\Services\MessageService;
use Calisero\Sms\Services\OptOutService;
use Calisero\Sms\Services\VerificationService;

final class Compliance
{
    public function __construct(
        private readonly OptOutService $optOuts,
        private readonly VerificationService $verifications,
        private readonly MessageService $messages,
    ) {
    }

    /**
     * GDPR: record that a number no longer wants to receive SMS.
     */
    public function optOut(string $phone, string $reason): string
    {
        return $this->optOuts->create(new CreateOptOutRequest($phone, $reason))->getData()->getId();
    }

    /**
     * Every opt-out, a page at a time.
     *
     * @return iterable<string> the opted-out numbers
     */
    public function optedOutPhones(): iterable
    {
        $page = 1;

        do {
            $result = $this->optOuts->list($page++);

            foreach ($result->getData() as $optOut) {
                yield $optOut->getPhone();
            }
        } while (null !== $result->getLinks()->getNext());
    }

    /**
     * The verifications completed, first page.
     *
     * @return list<string> their phone numbers
     */
    public function verifiedPhones(): array
    {
        return array_map(
            static fn ($verification): string => $verification->getPhone(),
            $this->verifications->list(1, 'verified')->getData(),
        );
    }

    /**
     * Cancel a scheduled message: false when it does not exist, or was already sent.
     */
    public function cancel(string $messageId): bool
    {
        try {
            $this->messages->delete($messageId);
        } catch (NotFoundException|ForbiddenException) {
            return false;
        }

        return true;
    }
}
