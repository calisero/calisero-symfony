<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Notifier;

use Calisero\SymfonySms\Support\ScheduleAt;
use Symfony\Component\Notifier\Message\MessageOptionsInterface;

/**
 * The Calisero options of an SMS sent through the Notifier:
 *
 *     $sms = new SmsMessage('+40712345678', 'Your order shipped: https://shop.example.com/o/123');
 *     $sms->options((new CaliseroOptions())->shortenUrls()->validity(24));
 *
 * The sender is the SmsMessage's `from`, or the `from` option of the DSN.
 */
final class CaliseroOptions implements MessageOptionsInterface
{
    /**
     * @param array<string, mixed> $options the parameters of SmsClientInterface::sendSms(), e.g. ['shorten_urls' => true]
     */
    public function __construct(
        private array $options = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->options;
    }

    public function getRecipientId(): ?string
    {
        return null;
    }

    /**
     * The body shown in the Calisero dashboard and API instead of the text, e.g. to keep
     * a code out of the logs.
     *
     * @return $this
     */
    public function visibleBody(string $visibleBody): static
    {
        $this->options['visible_body'] = $visibleBody;

        return $this;
    }

    /**
     * The message's validity period, in hours.
     *
     * @return $this
     */
    public function validity(int $hours): static
    {
        $this->options['validity'] = $hours;

        return $this;
    }

    /**
     * Send the message later. A date-time is converted to Romania time, the API's; a
     * string must already be a 'Y-m-d H:i:s' in Romania time.
     *
     * @return $this
     */
    public function scheduleAt(\DateTimeInterface|string $scheduleAt): static
    {
        $this->options['schedule_at'] = ScheduleAt::format($scheduleAt);

        return $this;
    }

    /**
     * The URL Calisero posts the delivery status to, instead of the bundle's webhook.
     *
     * @return $this
     */
    public function callbackUrl(string $callbackUrl): static
    {
        $this->options['callback_url'] = $callbackUrl;

        return $this;
    }

    /**
     * Have Calisero shorten the http:// and https:// links of the text; the short links
     * and their click counts come back in the message's shortened URLs.
     *
     * @return $this
     */
    public function shortenUrls(bool $shortenUrls = true): static
    {
        $this->options['shorten_urls'] = $shortenUrls;

        return $this;
    }
}
