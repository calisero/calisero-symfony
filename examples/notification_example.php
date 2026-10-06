<?php

declare(strict_types=1);

/*
 * Example: a notification sent by SMS
 *
 * The `sms` channel of the Notifier goes through the calisero transport
 * (examples/config/packages/notifier.yaml). A notification that implements
 * SmsNotificationInterface shapes its own SMS, Calisero options included.
 */

namespace App\Notification;

use Calisero\SymfonySms\Notifier\CaliseroOptions;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notification\SmsNotificationInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Notifier\Recipient\SmsRecipientInterface;

final class OrderShippedNotification extends Notification implements SmsNotificationInterface
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly string $trackingUrl,
    ) {
        parent::__construct("Order {$orderNumber} has shipped", ['sms']);
    }

    public function asSmsMessage(SmsRecipientInterface $recipient, ?string $transport = null): ?SmsMessage
    {
        $sms = new SmsMessage($recipient->getPhone(), "Order {$this->orderNumber} has shipped. Track it: {$this->trackingUrl}");

        return $sms->options((new CaliseroOptions())->shortenUrls());
    }
}

final class OrderShipping
{
    public function __construct(
        private readonly NotifierInterface $notifier,
    ) {
    }

    public function ship(string $orderNumber, string $customerPhone): void
    {
        // A Recipient, or your User implementing SmsRecipientInterface (getPhone())
        $this->notifier->send(
            new OrderShippedNotification($orderNumber, "https://shop.example.com/orders/{$orderNumber}"),
            new Recipient(phone: $customerPhone),
        );
    }
}
