<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\App;

use Calisero\SymfonySms\Event\CreditCriticalEvent;
use Calisero\SymfonySms\Event\CreditLowEvent;
use Calisero\SymfonySms\Event\DailyLimitLowEvent;
use Calisero\SymfonySms\Event\DeliveryStatusEvent;

/**
 * Prints the events of the bundle's webhook on the demo application's stderr, which
 * `make serve` shows: post a callback to the webhook and watch what it dispatches.
 */
final class DemoListener
{
    public function __invoke(object $event): void
    {
        $details = match (true) {
            $event instanceof DeliveryStatusEvent => json_encode($event->getPayload(), \JSON_UNESCAPED_SLASHES),
            $event instanceof CreditLowEvent, $event instanceof CreditCriticalEvent => 'remaining balance: '.$event->getRemainingBalance(),
            $event instanceof DailyLimitLowEvent => \sprintf('%d of %d left today', $event->getDailyRemaining(), $event->getDailyLimit()),
            default => '',
        };

        error_log(\sprintf('[calisero] %s %s', (new \ReflectionClass($event))->getShortName(), $details));
    }
}
