<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Notifier;

use Calisero\SymfonySms\Notifier\CaliseroOptions;
use PHPUnit\Framework\TestCase;

final class CaliseroOptionsTest extends TestCase
{
    public function testItHasNoOptionByDefault(): void
    {
        $options = new CaliseroOptions();

        $this->assertSame([], $options->toArray());
        $this->assertNull($options->getRecipientId());
    }

    public function testItGivesEveryOptionUnderTheNameOfSendSms(): void
    {
        $options = (new CaliseroOptions())
            ->visibleBody('Your code is ******.')
            ->validity(24)
            ->scheduleAt('2026-12-24 10:00:00')
            ->callbackUrl('https://hooks.example.test/sms')
            ->shortenUrls();

        $this->assertSame([
            'visible_body' => 'Your code is ******.',
            'validity' => 24,
            'schedule_at' => '2026-12-24 10:00:00',
            'callback_url' => 'https://hooks.example.test/sms',
            'shorten_urls' => true,
        ], $options->toArray());
    }

    public function testItConvertsAScheduleDateTimeToRomaniaTime(): void
    {
        $options = (new CaliseroOptions())->scheduleAt(new \DateTimeImmutable('2026-07-15 08:00:00', new \DateTimeZone('UTC')));

        $this->assertSame(['schedule_at' => '2026-07-15 11:00:00'], $options->toArray());
    }

    public function testShortenUrlsCanBeTurnedOff(): void
    {
        $this->assertSame(['shorten_urls' => false], (new CaliseroOptions())->shortenUrls(false)->toArray());
    }

    public function testItTakesTheOptionsAsAnArray(): void
    {
        $this->assertSame(['validity' => 2, 'shorten_urls' => true], (new CaliseroOptions(['validity' => 2, 'shorten_urls' => true]))->toArray());
    }
}
