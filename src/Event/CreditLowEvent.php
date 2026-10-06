<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched for a delivery webhook reporting a remaining balance at or below
 * calisero.credit.low_threshold, and above calisero.credit.critical_threshold.
 */
final class CreditLowEvent extends Event
{
    public function __construct(
        private readonly float $remainingBalance,
    ) {
    }

    /**
     * The account's balance after billing the message.
     */
    public function getRemainingBalance(): float
    {
        return $this->remainingBalance;
    }
}
