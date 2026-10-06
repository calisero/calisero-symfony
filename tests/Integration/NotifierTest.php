<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Integration;

use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\SymfonySms\Notifier\CaliseroOptions;
use Calisero\SymfonySms\Notifier\CaliseroTransportException;
use Calisero\SymfonySms\Tests\IntegrationTestCase;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Notifier\TexterInterface;

/**
 * Tests the calisero:// transport through the Notifier of the application
 * (framework.notifier.texter_transports: calisero://default?from=CALISERO).
 */
final class NotifierTest extends IntegrationTestCase
{
    public function testTheTexterSendsThroughCalisero(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $sentMessage = $this->texter()->send(new SmsMessage(TestPhones::DEFAULT, 'Your order shipped'));

        $this->assertNotNull($sentMessage);
        $this->assertSame(ApiFixtures::MESSAGE_ID, $sentMessage->getMessageId());
        $this->assertSame('calisero://rest.calisero.ro?from=CALISERO', $sentMessage->getTransport());
        $this->assertSame('https://rest.calisero.ro/api/v1/messages', (string) $this->transport()->lastRequest()->getUri());
        $this->assertSame('Bearer test-api-key', $this->transport()->lastHeader('Authorization'));
        $this->assertSame(['recipient' => TestPhones::DEFAULT, 'body' => 'Your order shipped', 'sender' => 'CALISERO'], $this->transport()->lastPayload());
    }

    public function testTheTexterSendsTheCaliseroOptions(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $this->texter()->send(new SmsMessage(TestPhones::DEFAULT, 'Track it: https://shop.example.test/o/1', 'MyShop', (new CaliseroOptions())->shortenUrls()->validity(24)));

        $this->assertSame([
            'recipient' => TestPhones::DEFAULT,
            'body' => 'Track it: https://shop.example.test/o/1',
            'validity' => 24,
            'sender' => 'MyShop',
            'shorten_urls' => true,
        ], $this->transport()->lastPayload());
    }

    public function testANotificationGoesOutAsAnSms(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $this->service('test.notifier', NotifierInterface::class)->send(new Notification('Your code is 123456', ['sms']), new Recipient('', TestPhones::DEFAULT));

        $this->assertSame(['recipient' => TestPhones::DEFAULT, 'body' => 'Your code is 123456', 'sender' => 'CALISERO'], $this->transport()->lastPayload());
    }

    public function testTheWebhookIsTheCallbackUrlOfTheNotifierMessagesToo(): void
    {
        $this->bootKernel(['api_key' => 'test-api-key', 'webhook' => ['enabled' => true, 'token' => 'secret']]);
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $this->texter()->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));

        $this->assertSame('https://app.example.test/calisero/webhook?token=secret', $this->transport()->lastPayload()['callback_url'] ?? null);
    }

    public function testAKeyInTheDsnWinsOverTheConfiguredOne(): void
    {
        $this->bootKernel(['api_key' => 'configured-key'], ['notifier' => ['texter_transports' => ['calisero' => 'calisero://dsn-key@default']]]);
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $this->texter()->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));

        $this->assertSame('Bearer dsn-key', $this->transport()->lastHeader('Authorization'));
        $this->assertArrayNotHasKey('sender', $this->transport()->lastPayload());
    }

    public function testAnApiRefusalReachesTheSender(): void
    {
        $this->transport()->respond(429, ApiFixtures::dailyLimitError());

        try {
            $this->texter()->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));
            $this->fail('The refusal was not reported.');
        } catch (CaliseroTransportException $e) {
            $this->assertInstanceOf(DailyLimitExceededException::class, $e->getApiException());
        }
    }

    private function texter(): TexterInterface
    {
        return $this->service('test.texter', TexterInterface::class);
    }
}
