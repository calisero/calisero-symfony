<?php

declare(strict_types=1);

/*
 * Example: send an SMS through the Notifier
 *
 * Needs symfony/notifier and the calisero transport (examples/config/packages/notifier.yaml).
 * The sender is the SmsMessage's `from`, else the DSN's `from` option, else Calisero's default.
 */

namespace App\Sms;

use Calisero\Sms\Dto\CreateMessageResponse;
use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\SymfonySms\Notifier\CaliseroOptions;
use Calisero\SymfonySms\Notifier\CaliseroTransportException;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

final class OtpTexter
{
    public function __construct(
        private readonly TexterInterface $texter,
    ) {
    }

    public function send(string $phone, string $code): ?string
    {
        $sms = new SmsMessage($phone, "Your code is {$code}. It expires in 5 minutes.");

        // Optional: the Calisero options of the message
        $sms->options((new CaliseroOptions())
            ->visibleBody('Your code is ******. It expires in 5 minutes.') // keeps the code out of the dashboard
            ->validity(1));                                                  // hours

        try {
            $sentMessage = $this->texter->send($sms);
        } catch (CaliseroTransportException $e) {
            // The SDK's exception tells why: $e->getApiException()
            if ($e->getApiException() instanceof DailyLimitExceededException) {
                // Nothing was sent: the daily sending limit is reached until midnight, Romania time
            }

            error_log($e->getMessage()."\n".$e->getDebug()); // the HTTP status, the trace ID and the error body

            return null;
        }

        // The SDK's answer, on Symfony 7.3 and later: parts, status, daily limit left...
        $response = null !== $sentMessage && method_exists($sentMessage, 'getInfo') ? $sentMessage->getInfo('response') : null;

        if ($response instanceof CreateMessageResponse) {
            error_log('Left today: '.($response->getResponseMeta()->getDailyRemaining() ?? 'no daily limit'));
        }

        return $sentMessage?->getMessageId(); // the Calisero message ID
    }
}
