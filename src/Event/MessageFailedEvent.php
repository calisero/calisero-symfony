<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Event;

/**
 * Dispatched for a delivery webhook with the status undelivered (or failed): the
 * delivery failed for good.
 */
final class MessageFailedEvent extends DeliveryStatusEvent
{
}
