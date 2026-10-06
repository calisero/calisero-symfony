<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Event;

/**
 * Dispatched for a delivery webhook with the status sent: the message was accepted and
 * handed to the network.
 */
final class MessageSentEvent extends DeliveryStatusEvent
{
}
