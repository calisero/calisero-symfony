<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched for a delivery webhook reporting that the account can send no more than
 * calisero.daily_limit.low_threshold messages today. The limit resets at midnight,
 * Romania time; past it, sending throws DailyLimitExceededException.
 */
final class DailyLimitLowEvent extends Event
{
    public function __construct(
        private readonly int $dailyLimit,
        private readonly int $dailyRemaining,
        private readonly ?int $sentToday = null,
    ) {
    }

    /**
     * How many messages the account can send in a day.
     */
    public function getDailyLimit(): int
    {
        return $this->dailyLimit;
    }

    /**
     * How many messages the account could still send today when the callback was sent.
     */
    public function getDailyRemaining(): int
    {
        return $this->dailyRemaining;
    }

    /**
     * The real messages the account created today, Romania time; null in payloads that
     * do not carry it.
     */
    public function getSentToday(): ?int
    {
        return $this->sentToday;
    }
}
