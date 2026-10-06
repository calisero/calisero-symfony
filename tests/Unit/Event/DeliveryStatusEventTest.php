<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Event;

use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Calisero\SymfonySms\Event\MessageFailedEvent;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use PHPUnit\Framework\TestCase;

final class DeliveryStatusEventTest extends TestCase
{
    public function testItReadsThePayloadWithTheSdk(): void
    {
        $event = new MessageDeliveredEvent(ApiFixtures::webhookPayload([
            'status' => 'delivered',
            'deliveredAt' => '2026-01-01T12:00:24.000000Z',
            'remainingBalance' => 999.43,
            'dailyLimit' => 1000,
            'dailyRemaining' => 588,
            'sentToday' => 412,
        ]));

        $message = $event->getMessage();

        $this->assertNotNull($message);
        $this->assertSame('019961d8-3338-700c-be17-10d061f03a5c', $message->getMessageId());
        $this->assertSame(TestPhones::DEFAULT, $message->getRecipient());
        $this->assertSame('delivered', $message->getStatus());
        $this->assertSame('2026-01-01T12:00:24.000000Z', $message->getDeliveredAt());
        $this->assertSame(0.0378, $message->getPrice());
        $this->assertSame(999.43, $message->getRemainingBalance());
        $this->assertSame(588, $message->getDailyRemaining());
        $this->assertSame(412, $message->getSentToday());
    }

    public function testItGivesTheMessageIdAndTheStatusOfTheRawPayload(): void
    {
        $event = new MessageFailedEvent(ApiFixtures::webhookPayload(['status' => 'undelivered']));

        $this->assertSame('019961d8-3338-700c-be17-10d061f03a5c', $event->getMessageId());
        $this->assertSame('undelivered', $event->getStatus());
        $this->assertSame(ApiFixtures::webhookPayload(['status' => 'undelivered']), $event->getPayload());
    }

    /**
     * A payload without a required field cannot be read with typed getters; its raw
     * values are still there.
     */
    public function testItGivesNoMessageForAPayloadMissingARequiredField(): void
    {
        $payload = ApiFixtures::webhookPayload(['status' => 'delivered']);
        unset($payload['price']);

        $event = new MessageDeliveredEvent($payload);

        $this->assertNull($event->getMessage());
        $this->assertSame('019961d8-3338-700c-be17-10d061f03a5c', $event->getMessageId());
        $this->assertSame($payload, $event->getPayload());
    }

    public function testItReadsThePayloadOnce(): void
    {
        $event = new MessageDeliveredEvent(ApiFixtures::webhookPayload(['status' => 'delivered']));

        $this->assertSame($event->getMessage(), $event->getMessage());
    }

    public function testItGivesNoMessageIdOrStatusThatIsNotAString(): void
    {
        $event = new MessageFailedEvent(['messageId' => 42, 'status' => ['undelivered']]);

        $this->assertNull($event->getMessageId());
        $this->assertNull($event->getStatus());
    }
}
