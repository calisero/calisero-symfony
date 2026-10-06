<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Integration;

use Calisero\SymfonySms\Event\CreditCriticalEvent;
use Calisero\SymfonySms\Event\CreditLowEvent;
use Calisero\SymfonySms\Event\DailyLimitLowEvent;
use Calisero\SymfonySms\Event\DeliveryStatusEvent;
use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Calisero\SymfonySms\Event\MessageFailedEvent;
use Calisero\SymfonySms\Event\MessageSentEvent;
use Calisero\SymfonySms\Tests\Doubles\EventRecorder;
use Calisero\SymfonySms\Tests\IntegrationTestCase;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the delivery webhook over HTTP, through the application: the route, the token,
 * the answers and the events dispatched.
 */
final class WebhookTest extends IntegrationTestCase
{
    private const MESSAGE_EVENTS = [MessageSentEvent::class, MessageDeliveredEvent::class, MessageFailedEvent::class];

    private const MONITORING_EVENTS = [CreditLowEvent::class, CreditCriticalEvent::class, DailyLimitLowEvent::class];

    /**
     * @param class-string<DeliveryStatusEvent> $expected
     */
    #[DataProvider('statusEvents')]
    public function testItDispatchesTheEventOfTheStatus(string $status, string $expected): void
    {
        $events = $this->bootWebhook();
        $payload = ApiFixtures::webhookPayload(['status' => $status]);

        $response = $this->postWebhook($payload);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"ok":true}', $response->getContent());
        $this->assertSame([$expected], $events->classes());
        $this->assertSame($payload, $events->of($expected)[0]->getPayload());
    }

    /**
     * @return iterable<string, array{string, class-string<DeliveryStatusEvent>}>
     */
    public static function statusEvents(): iterable
    {
        yield 'sent' => ['sent', MessageSentEvent::class];
        yield 'delivered' => ['delivered', MessageDeliveredEvent::class];
        yield 'undelivered' => ['undelivered', MessageFailedEvent::class];
        yield 'failed, posted by hand' => ['failed', MessageFailedEvent::class];
    }

    #[DataProvider('unrecognisedStatuses')]
    public function testItDispatchesNothingForAnUnrecognisedStatus(mixed $status): void
    {
        $events = $this->bootWebhook();

        $response = $this->postWebhook(ApiFixtures::webhookPayload(['status' => $status]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $events->events);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unrecognisedStatuses(): iterable
    {
        yield 'an intermediate status' => ['processing'];
        yield 'the wrong case' => ['Delivered'];
        yield 'null' => [null];
    }

    public function testItDispatchesNothingWithoutAStatus(): void
    {
        $events = $this->bootWebhook();
        $payload = ApiFixtures::webhookPayload();
        unset($payload['status']);

        $this->assertSame(200, $this->postWebhook($payload)->getStatusCode());
        $this->assertSame([], $events->events);
    }

    public function testTheEventReadsThePayloadWithTheSdk(): void
    {
        $events = $this->bootWebhook();

        $this->postWebhook(ApiFixtures::webhookPayload(['status' => 'delivered', 'deliveredAt' => '2026-01-01T12:00:24.000000Z', 'dailyLimit' => 1000, 'dailyRemaining' => 588]));

        $message = $events->of(MessageDeliveredEvent::class)[0]->getMessage();
        $this->assertNotNull($message);
        $this->assertSame('2026-01-01T12:00:24.000000Z', $message->getDeliveredAt());
        $this->assertSame(588, $message->getDailyRemaining());
    }

    #[DataProvider('invalidBodies')]
    public function testItRefusesABodyThatIsNotAJsonObject(string $body): void
    {
        $events = $this->bootWebhook();

        $response = $this->postWebhook($body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('{"error":"Invalid webhook payload: expected a JSON object"}', $response->getContent());
        $this->assertSame([], $events->events);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'no body' => [''];
        yield 'not JSON' => ['status=delivered'];
        yield 'a JSON list' => ['["delivered"]'];
        yield 'a JSON string' => ['"delivered"'];
    }

    public function testItAnswersOnlyPostRequests(): void
    {
        $this->bootWebhook();

        $this->assertSame(405, $this->handle(Request::create('/calisero/webhook'))->getStatusCode());
    }

    public function testItHasNoRouteWhileTheWebhookIsDisabled(): void
    {
        $this->bootKernel(['api_key' => 'test-api-key', 'webhook' => ['enabled' => false]]);

        $this->assertSame(404, $this->postWebhook(ApiFixtures::webhookPayload())->getStatusCode());
    }

    public function testItAnswersAtTheConfiguredPath(): void
    {
        $this->bootKernel(['api_key' => 'test-api-key', 'webhook' => ['enabled' => true, 'path' => '/hooks/sms']]);
        $events = $this->recordEvents(...self::MESSAGE_EVENTS);

        $this->assertSame(200, $this->postWebhook(ApiFixtures::webhookPayload(), '/hooks/sms')->getStatusCode());
        $this->assertSame(404, $this->postWebhook(ApiFixtures::webhookPayload())->getStatusCode());
        $this->assertSame([MessageSentEvent::class], $events->classes());
    }

    public function testItAcceptsACallbackWithTheToken(): void
    {
        $events = $this->bootWebhook(['token' => 's3cr+t/1']);

        $response = $this->postWebhook(ApiFixtures::webhookPayload(), '/calisero/webhook?token=s3cr%2Bt/1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([MessageSentEvent::class], $events->classes());
    }

    #[DataProvider('wrongTokens')]
    public function testItRefusesACallbackWithoutTheToken(string $query): void
    {
        $events = $this->bootWebhook(['token' => 'secret']);

        $response = $this->postWebhook(ApiFixtures::webhookPayload(), '/calisero/webhook'.$query);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('{"error":"Invalid webhook token"}', $response->getContent());
        $this->assertSame([], $events->events);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongTokens(): iterable
    {
        yield 'no token' => [''];
        yield 'an empty token' => ['?token='];
        yield 'another token' => ['?token=guess'];
        yield 'a prefix of the token' => ['?token=secre'];
        yield 'the token and more' => ['?token=secret2'];
        yield 'the token in another case' => ['?token=SECRET'];
        yield 'the token as an array' => ['?token[]=secret'];
    }

    public function testItDispatchesTheLowCreditEvent(): void
    {
        $events = $this->bootWebhook(credit: ['low_threshold' => 500.0, 'critical_threshold' => 100.0]);

        $this->postWebhook(ApiFixtures::webhookPayload(['remainingBalance' => 450.00]));

        $this->assertSame([MessageSentEvent::class, CreditLowEvent::class], $events->classes());
        $this->assertSame(450.0, $events->of(CreditLowEvent::class)[0]->getRemainingBalance());
    }

    public function testItDispatchesOnlyTheCriticalCreditEventBelowBothThresholds(): void
    {
        $events = $this->bootWebhook(credit: ['low_threshold' => 500.0, 'critical_threshold' => 100.0]);

        $this->postWebhook(ApiFixtures::webhookPayload(['remainingBalance' => 50.00]));

        $this->assertSame([MessageSentEvent::class, CreditCriticalEvent::class], $events->classes());
        $this->assertSame(50.0, $events->of(CreditCriticalEvent::class)[0]->getRemainingBalance());
    }

    /**
     * @param list<class-string> $expected
     */
    #[DataProvider('balances')]
    public function testItComparesTheBalanceWithTheThresholds(mixed $balance, array $expected): void
    {
        $events = $this->bootWebhook(credit: ['low_threshold' => 500.0, 'critical_threshold' => 100.0]);

        $this->postWebhook(ApiFixtures::webhookPayload(['remainingBalance' => $balance]));

        $this->assertSame($expected, array_values(array_filter($events->classes(), static fn (string $class): bool => \in_array($class, self::MONITORING_EVENTS, true))));
    }

    /**
     * @return iterable<string, array{mixed, list<class-string>}>
     */
    public static function balances(): iterable
    {
        yield 'on the low threshold' => [500.0, [CreditLowEvent::class]];
        yield 'on the critical threshold' => [100, [CreditCriticalEvent::class]];
        yield 'above both' => [750.0, []];
        yield 'a number in a string' => ['99.5', [CreditCriticalEvent::class]];
        yield 'not a number' => ['unknown', []];
        yield 'no balance' => [null, []];
    }

    public function testItDispatchesNoCreditEventWhileTheThresholdsAreUnset(): void
    {
        $events = $this->bootWebhook();

        $this->postWebhook(ApiFixtures::webhookPayload(['remainingBalance' => 0.5]));

        $this->assertSame([MessageSentEvent::class], $events->classes());
    }

    public function testItDispatchesTheDailyLimitEventAtTheThreshold(): void
    {
        $events = $this->bootWebhook(dailyLimit: ['low_threshold' => 100]);

        $this->postWebhook(ApiFixtures::webhookPayload(['dailyLimit' => 1000, 'dailyRemaining' => 100, 'sentToday' => 900]));

        $event = $events->of(DailyLimitLowEvent::class)[0] ?? null;
        $this->assertNotNull($event);
        $this->assertSame(1000, $event->getDailyLimit());
        $this->assertSame(100, $event->getDailyRemaining());
        $this->assertSame(900, $event->getSentToday());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('dailyLimitsNotLow')]
    public function testItDispatchesNoDailyLimitEvent(array $payload, ?int $threshold): void
    {
        $events = $this->bootWebhook(dailyLimit: ['low_threshold' => $threshold]);

        $this->postWebhook(ApiFixtures::webhookPayload($payload));

        $this->assertSame([], $events->of(DailyLimitLowEvent::class));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?int}>
     */
    public static function dailyLimitsNotLow(): iterable
    {
        yield 'above the threshold' => [['dailyLimit' => 1000, 'dailyRemaining' => 101], 100];
        yield 'an account without a daily limit' => [['dailyLimit' => null, 'dailyRemaining' => null], 100];
        yield 'no threshold' => [['dailyLimit' => 1000, 'dailyRemaining' => 0], null];
    }

    public function testItReadsTheDailyLimitFromStringsOfDigits(): void
    {
        $events = $this->bootWebhook(dailyLimit: ['low_threshold' => 100]);

        $this->postWebhook(ApiFixtures::webhookPayload(['dailyLimit' => '1000', 'dailyRemaining' => '5', 'sentToday' => null]));

        $event = $events->of(DailyLimitLowEvent::class)[0] ?? null;
        $this->assertNotNull($event);
        $this->assertSame(5, $event->getDailyRemaining());
        $this->assertNull($event->getSentToday());
    }

    /**
     * @param array<string, mixed>  $webhook
     * @param array<string, ?float> $credit
     * @param array<string, ?int>   $dailyLimit
     */
    private function bootWebhook(array $webhook = [], array $credit = [], array $dailyLimit = []): EventRecorder
    {
        $this->bootKernel([
            'api_key' => 'test-api-key',
            'webhook' => ['enabled' => true] + $webhook,
            'credit' => $credit,
            'daily_limit' => $dailyLimit,
        ]);

        return $this->recordEvents(...self::MESSAGE_EVENTS, ...self::MONITORING_EVENTS);
    }
}
