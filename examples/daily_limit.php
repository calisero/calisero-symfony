<?php

declare(strict_types=1);

/*
 * Example: the daily sending limit
 *
 * Every Calisero account has its own daily sending limit. Each real message counts once,
 * verification codes included; test messages never count. The day ends at midnight,
 * Romania time. Once the limit is reached, the API refuses messages until then and the
 * SDK throws DailyLimitExceededException: nothing is sent and nothing is billed.
 */

namespace App\Sms;

use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\Sms\Exceptions\RateLimitedException;
use Calisero\SymfonySms\Event\DailyLimitLowEvent;
use Calisero\SymfonySms\SmsClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class DailyLimitAware
{
    public function __construct(
        private readonly SmsClientInterface $sms,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * 1. Read the limit (needs calisero.account_id); `bin/console calisero:account` shows it too.
     */
    public function report(): string
    {
        $account = $this->sms->getAccount();

        if (null === $account->getDailyLimit()) {
            return 'No daily sending limit applies';
        }

        return \sprintf('Sent today: %d of %d, %d left until midnight, Romania time', $account->getSentToday(), $account->getDailyLimit(), $account->getDailyRemaining());
    }

    /**
     * 2. Tell a refusal of the daily limit from the request rate limit: catch
     *    DailyLimitExceededException first, it extends RateLimitedException.
     *
     * @return int|null seconds to wait before trying again, null when the SMS was sent
     */
    public function send(string $phone, string $text): ?int
    {
        try {
            $this->sms->sendSms(['to' => $phone, 'text' => $text]);
        } catch (DailyLimitExceededException $e) {
            $this->logger->warning('Calisero daily limit reached', [
                'limit' => $e->getDailyLimit(),
                'resets_at' => $e->getResetsAt(), // e.g. 2026-10-07T00:00:00+03:00
                'trace_id' => $e->getTraceId(),
            ]);

            return $e->getRetryAfter() ?? 3600; // until midnight, Romania time
        } catch (RateLimitedException $e) {
            return $e->getRetryAfter() ?? 5;    // the request rate limit frees up quickly
        }

        return null;
    }

    /**
     * 3. Be warned before the limit is reached: with calisero.daily_limit.low_threshold set
     *    and the webhook enabled, every delivery callback reporting that many messages
     *    left, or fewer, dispatches DailyLimitLowEvent.
     */
    #[AsEventListener]
    public function onDailyLimitLow(DailyLimitLowEvent $event): void
    {
        $this->logger->warning('Calisero daily limit almost reached', [
            'limit' => $event->getDailyLimit(),
            'remaining' => $event->getDailyRemaining(),
            'sent_today' => $event->getSentToday(),
        ]);
    }
}
