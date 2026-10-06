<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Event;

/**
 * Dispatched for a delivery webhook with the status delivered: the handset or the
 * network confirmed the delivery.
 */
final class MessageDeliveredEvent extends DeliveryStatusEvent
{
}
