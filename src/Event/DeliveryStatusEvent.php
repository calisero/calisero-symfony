<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Event;

use Calisero\Sms\Dto\DeliveryWebhookMessage;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A delivery status callback of Calisero, dispatched by the bundle's webhook: the parent
 * of MessageSentEvent, MessageDeliveredEvent and MessageFailedEvent.
 */
abstract class DeliveryStatusEvent extends Event
{
    private ?DeliveryWebhookMessage $message = null;

    private bool $parsed = false;

    /**
     * @param array<string, mixed> $payload the callback's JSON body, decoded
     */
    public function __construct(
        private readonly array $payload,
    ) {
    }

    /**
     * The callback's JSON body, decoded, as Calisero sent it.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * The payload as the SDK reads it, with typed getters for every field (the daily
     * limit's included); null when the payload lacks a required field, whose raw values
     * stay in getPayload().
     */
    public function getMessage(): ?DeliveryWebhookMessage
    {
        if (!$this->parsed) {
            $this->parsed = true;

            try {
                $this->message = DeliveryWebhookMessage::fromArray($this->payload);
            } catch (\InvalidArgumentException) {
                $this->message = null;
            }
        }

        return $this->message;
    }

    /**
     * The message's ID, as returned when it was sent: match the callback to your records
     * with it.
     */
    public function getMessageId(): ?string
    {
        $messageId = $this->payload['messageId'] ?? null;

        return \is_string($messageId) && '' !== $messageId ? $messageId : null;
    }

    /**
     * The delivery status: sent, delivered or undelivered.
     */
    public function getStatus(): ?string
    {
        $status = $this->payload['status'] ?? null;

        return \is_string($status) ? $status : null;
    }
}
