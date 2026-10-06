<?php

declare(strict_types=1);

/*
 * Example: shortened URLs, scheduling and what the answer reports
 *
 * shorten_urls replaces the http:// and https:// links of the text with short ones; the
 * message lists them, with their click counts once it is read again. schedule_at takes a
 * \DateTimeInterface, converted to the 'Y-m-d H:i:s', Romania time, the API expects.
 */

namespace App\Sms;

use Calisero\SymfonySms\SmsClientInterface;

final class TrackingLinkSender
{
    public function __construct(
        private readonly SmsClientInterface $sms,
    ) {
    }

    public function send(string $phone, string $trackingUrl): string
    {
        $response = $this->sms->sendSms([
            'to' => $phone,
            'text' => 'Your parcel is on its way: '.$trackingUrl,
            'shorten_urls' => true,
            'schedule_at' => new \DateTimeImmutable('tomorrow 09:00', new \DateTimeZone('Europe/Bucharest')),
            'validity' => 24,                          // hours
            'visible_body' => 'Your parcel is on its way', // shown in the dashboard instead of the text
        ]);

        foreach ($response->getData()->getShortenedUrls() as $link) {
            echo $link->getOriginalLink().' -> '.$link->getShortenedLink().\PHP_EOL;
        }

        $meta = $response->getResponseMeta();
        echo 'Trace ID: '.$meta->getTraceId().\PHP_EOL;              // quote it to Calisero support
        echo 'Left today: '.($meta->getDailyRemaining() ?? 'no daily limit').\PHP_EOL;

        return $response->getData()->getId();
    }

    /**
     * Later: how many times the links were opened.
     */
    public function clicks(string $messageId): void
    {
        foreach ($this->sms->getMessageStatus($messageId)->getData()->getShortenedUrls() as $link) {
            echo $link->getShortenedLink().': '.$link->getClickCount().' clicks, last '.($link->getLastClick() ?? 'never').\PHP_EOL;
        }
    }
}
