<?php

declare(strict_types=1);

/*
 * Example: react to the delivery status callbacks
 *
 * With calisero.webhook.enabled on and the routes imported, every message that sets no
 * callback_url is sent with the webhook's, and each callback dispatches an event.
 * Calisero retries a callback only when it gets no answer within 2 seconds: keep the
 * listeners fast, and hand slow work to Messenger.
 */

namespace App\EventListener;

use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Calisero\SymfonySms\Event\MessageFailedEvent;
use Calisero\SymfonySms\Event\MessageSentEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class SmsDeliveryListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    // "sent": accepted and handed to the network
    #[AsEventListener]
    public function onSent(MessageSentEvent $event): void
    {
        $this->logger->info('SMS sent', ['message_id' => $event->getMessageId()]);
    }

    // "delivered": the handset confirmed it
    #[AsEventListener]
    public function onDelivered(MessageDeliveredEvent $event): void
    {
        // Typed getters, from the SDK's DeliveryWebhookMessage; null when a required field is missing
        $message = $event->getMessage();

        $this->logger->info('SMS delivered', [
            'message_id' => $message?->getMessageId(),
            'delivered_at' => $message?->getDeliveredAt(),
            'price' => $message?->getPrice(),
            'daily_remaining' => $message?->getDailyRemaining(), // null when the account has no daily limit
        ]);
    }

    // "undelivered": the delivery failed for good
    #[AsEventListener]
    public function onFailed(MessageFailedEvent $event): void
    {
        // The raw payload, as Calisero sent it
        $this->logger->warning('SMS not delivered', $event->getPayload());
    }
}
