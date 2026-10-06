<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Notifier;

use Calisero\Sms\Dto\CreateMessageResponse;
use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\Sms\Exceptions\TransportException;
use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\Notifier\CaliseroOptions;
use Calisero\SymfonySms\Notifier\CaliseroTransport;
use Calisero\SymfonySms\Notifier\CaliseroTransportException;
use Calisero\SymfonySms\SmsClient;
use Calisero\SymfonySms\Tests\Doubles\EventRecorder;
use Calisero\SymfonySms\Tests\Doubles\StubTransport;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Notifier\Event\FailedMessageEvent;
use Symfony\Component\Notifier\Event\MessageEvent;
use Symfony\Component\Notifier\Event\SentMessageEvent;
use Symfony\Component\Notifier\Exception\LogicException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageOptionsInterface;
use Symfony\Component\Notifier\Message\SmsMessage;

final class CaliseroTransportTest extends TestCase
{
    private StubTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new StubTransport();
    }

    public function testItSupportsSmsMessages(): void
    {
        $transport = $this->transport();

        $this->assertTrue($transport->supports(new SmsMessage(TestPhones::DEFAULT, 'Hello')));
        $this->assertTrue($transport->supports(new SmsMessage(TestPhones::DEFAULT, 'Hello', '', new CaliseroOptions())));
        $this->assertFalse($transport->supports(new SmsMessage(TestPhones::DEFAULT, 'Hello', '', self::foreignOptions())));
        $this->assertFalse($transport->supports(new ChatMessage('Hello')));
    }

    public function testItSendsThePhoneAndTheText(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->transport()->send(new SmsMessage(TestPhones::DEFAULT, 'Your order shipped'));

        $this->assertSame('https://rest.calisero.test/api/v1/messages', (string) $this->transport->lastRequest()->getUri());
        $this->assertSame(['recipient' => TestPhones::DEFAULT, 'body' => 'Your order shipped'], $this->transport->lastPayload());
    }

    public function testItSendsTheCaliseroOptions(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $options = (new CaliseroOptions())->visibleBody('Your code is ******.')->validity(1)->shortenUrls()->callbackUrl('https://hooks.example.test/sms');
        $this->transport()->send(new SmsMessage(TestPhones::DEFAULT, 'Your code is 123456.', '', $options));

        $this->assertSame([
            'recipient' => TestPhones::DEFAULT,
            'body' => 'Your code is 123456.',
            'visible_body' => 'Your code is ******.',
            'validity' => 1,
            'callback_url' => 'https://hooks.example.test/sms',
            'shorten_urls' => true,
        ], $this->transport->lastPayload());
    }

    public function testTheSenderOfTheMessageWinsOverTheOneOfTheDsn(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->transport('CALISERO')->send(new SmsMessage(TestPhones::DEFAULT, 'Hello', 'MyShop'));

        $this->assertSame('MyShop', $this->transport->lastPayload()['sender'] ?? null);
    }

    public function testTheSenderOfTheDsnIsTheDefaultOne(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->transport('CALISERO')->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));

        $this->assertSame('CALISERO', $this->transport->lastPayload()['sender'] ?? null);
    }

    public function testWithoutASenderCaliseroPicksTheDefaultOne(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->transport()->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));

        $this->assertArrayNotHasKey('sender', $this->transport->lastPayload());
    }

    public function testTheSentMessageCarriesTheMessageIdAndTheAnswer(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message(['parts' => 2])], ['X-Daily-Limit' => '1000', 'X-Daily-Remaining' => '872']);

        $sentMessage = $this->transport('CALISERO')->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));

        $this->assertSame(ApiFixtures::MESSAGE_ID, $sentMessage->getMessageId());
        $this->assertSame('calisero://rest.calisero.test?from=CALISERO', $sentMessage->getTransport());

        if (method_exists($sentMessage, 'getInfo')) {
            $response = $sentMessage->getInfo('response');
            $this->assertInstanceOf(CreateMessageResponse::class, $response);
            $this->assertSame(2, $response->getData()->getParts());
            $this->assertSame(872, $response->getResponseMeta()->getDailyRemaining());
        }
    }

    public function testAnApiRefusalIsATransportExceptionOfTheNotifier(): void
    {
        $this->transport->respond(429, ApiFixtures::dailyLimitError(), ['X-Trace-Id' => ApiFixtures::TRACE_ID]);

        try {
            $this->transport()->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));
            $this->fail('The refusal was not reported.');
        } catch (CaliseroTransportException $e) {
            $this->assertInstanceOf(TransportExceptionInterface::class, $e);
            $this->assertInstanceOf(DailyLimitExceededException::class, $e->getApiException());
            $this->assertSame($e->getApiException(), $e->getPrevious());
            $this->assertSame(429, $e->getCode());
            $this->assertStringStartsWith('Unable to send the SMS through Calisero: This account can send at most 1,000 messages a day.', $e->getMessage());
            $this->assertStringContainsString('HTTP status: 429', $e->getDebug());
            $this->assertStringContainsString('Trace ID: '.ApiFixtures::TRACE_ID, $e->getDebug());
            $this->assertStringContainsString('"code":"daily_limit_exceeded"', $e->getDebug());
        }
    }

    public function testARequestWithoutAnAnswerIsATransportExceptionOfTheNotifier(): void
    {
        $this->transport->fail();

        try {
            $this->transport()->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));
            $this->fail('The failure was not reported.');
        } catch (CaliseroTransportException $e) {
            $this->assertInstanceOf(TransportException::class, $e->getApiException());
            $this->assertSame(0, $e->getCode());
            $this->assertStringContainsString('HTTP status: none (no answer)', $e->getDebug());
        }
    }

    public function testItRefusesAMessageThatIsNotAnSms(): void
    {
        $this->expectException(UnsupportedMessageTypeException::class);

        $this->transport()->send(new ChatMessage('Hello'));
    }

    public function testItRefusesTheOptionsOfAnotherTransport(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only supports instances of "Calisero\SymfonySms\Notifier\CaliseroOptions" for options');

        $this->transport()->send(new SmsMessage(TestPhones::DEFAULT, 'Hello', '', self::foreignOptions()));
    }

    public function testItDispatchesTheNotifierEventsOfASentMessage(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);
        $recorder = $this->recordNotifierEvents($dispatcher = new EventDispatcher());

        $message = new SmsMessage(TestPhones::DEFAULT, 'Hello');
        $sentMessage = $this->transport(null, $dispatcher)->send($message);

        $this->assertSame([MessageEvent::class, SentMessageEvent::class], $recorder->classes());
        $this->assertSame($message, $recorder->of(MessageEvent::class)[0]->getMessage());
        $this->assertSame($sentMessage, $recorder->of(SentMessageEvent::class)[0]->getMessage());
    }

    public function testItDispatchesTheNotifierEventsOfAFailedMessage(): void
    {
        $this->transport->respond(422, ApiFixtures::validationError());
        $recorder = $this->recordNotifierEvents($dispatcher = new EventDispatcher());

        try {
            $this->transport(null, $dispatcher)->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));
            $this->fail('The refusal was not reported.');
        } catch (CaliseroTransportException $e) {
            $this->assertSame([MessageEvent::class, FailedMessageEvent::class], $recorder->classes());
            $this->assertSame($e, $recorder->of(FailedMessageEvent::class)[0]->getError());
        }
    }

    private function transport(?string $from = null, ?EventDispatcher $dispatcher = null): CaliseroTransport
    {
        $client = new SmsClient((new ClientFactory($this->transport, 'https://rest.calisero.test/api/v1'))->make('test-api-key'));

        return new CaliseroTransport($client, 'rest.calisero.test', $from, $dispatcher);
    }

    private function recordNotifierEvents(EventDispatcher $dispatcher): EventRecorder
    {
        $recorder = new EventRecorder();

        foreach ([MessageEvent::class, SentMessageEvent::class, FailedMessageEvent::class] as $event) {
            $dispatcher->addListener($event, $recorder);
        }

        return $recorder;
    }

    private static function foreignOptions(): MessageOptionsInterface
    {
        return new class implements MessageOptionsInterface {
            /**
             * @return array<string, mixed>
             */
            public function toArray(): array
            {
                return [];
            }

            public function getRecipientId(): ?string
            {
                return null;
            }
        };
    }
}
