<?php

declare(strict_types=1);

/*
 * Example: alerts when the credit runs low
 *
 * With calisero.credit.low_threshold and critical_threshold set, every delivery callback
 * compares the account's remaining balance with them. Below the critical threshold only
 * CreditCriticalEvent is dispatched.
 */

namespace App\EventListener;

use Calisero\SymfonySms\Event\CreditCriticalEvent;
use Calisero\SymfonySms\Event\CreditLowEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class CreditListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onLow(CreditLowEvent $event): void
    {
        $this->logger->warning('Calisero credit low', ['remaining' => $event->getRemainingBalance()]);
    }

    #[AsEventListener]
    public function onCritical(CreditCriticalEvent $event): void
    {
        // Notify the team: Slack, e-mail... and top the account up
        $this->logger->critical('Calisero credit CRITICAL', ['remaining' => $event->getRemainingBalance()]);
    }
}
