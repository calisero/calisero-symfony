<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Contract;

use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\SymfonySms\Event\DeliveryStatusEvent;
use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Calisero\SymfonySms\Event\MessageFailedEvent;
use Calisero\SymfonySms\Event\MessageSentEvent;
use Calisero\SymfonySms\Notifier\CaliseroOptions;
use Calisero\SymfonySms\SmsClientInterface;
use Calisero\SymfonySms\Tests\IntegrationTestCase;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

/**
 * Tests the bundle against the API's OpenAPI document (resources/openApi/api-v1.json):
 * every field the API takes can be sent, every status of the webhook has its event.
 *
 * When the API changes, replace the document: these tests then show what the bundle lacks.
 */
final class OpenApiContractTest extends IntegrationTestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    public function testTheDocumentIsTheOneOfTheSupportedApiVersion(): void
    {
        $this->assertStringStartsWith('1.0.14', self::string(self::path(self::document(), 'info', 'version')));
    }

    public function testEveryFieldOfAMessageCanBeSent(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $this->client()->sendSms([
            'to' => TestPhones::DEFAULT,
            'text' => 'Hello https://shop.example.test',
            'from' => 'CALISERO',
            'visible_body' => 'Hello',
            'validity' => 24,
            'schedule_at' => new \DateTimeImmutable('+1 day'),
            'callback_url' => 'https://hooks.example.test/sms',
            'shorten_urls' => true,
        ]);

        $this->assertSameKeys(self::schemaProperties('CreateMessageRequest'), $this->transport()->lastPayload());
    }

    public function testEveryFieldOfAMessageCanBeSentThroughTheNotifier(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $options = (new CaliseroOptions())
            ->visibleBody('Hello')
            ->validity(24)
            ->scheduleAt(new \DateTimeImmutable('+1 day'))
            ->callbackUrl('https://hooks.example.test/sms')
            ->shortenUrls();
        $this->service('test.texter', TexterInterface::class)->send(new SmsMessage(TestPhones::DEFAULT, 'Hello https://shop.example.test', 'CALISERO', $options));

        $this->assertSameKeys(self::schemaProperties('CreateMessageRequest'), $this->transport()->lastPayload());
    }

    public function testEveryFieldOfAVerificationCanBeSent(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::verification()]);

        $this->client()->sendVerification(['to' => TestPhones::DEFAULT, 'brand' => 'MyApp', 'template' => 'Your code is {code}', 'expires_in' => 5]);

        $this->assertSameKeys(self::schemaProperties('CreateVerificationRequest'), $this->transport()->lastPayload());
    }

    public function testEveryFieldOfAVerificationCheckIsSent(): void
    {
        $this->transport()->respond(200, ['data' => ApiFixtures::verification(['status' => 'verified'])]);

        $this->client()->checkVerification(['to' => TestPhones::DEFAULT, 'code' => 'SBMH0f']);

        $this->assertSameKeys(self::schemaProperties('VerificationCheckRequest'), $this->transport()->lastPayload());
    }

    public function testTheDocumentedWebhookIsDispatchedAsItsStatusEvent(): void
    {
        $this->bootKernel(['api_key' => 'test-api-key', 'webhook' => ['enabled' => true]]);
        $events = $this->recordEvents(MessageDeliveredEvent::class);

        $example = self::array(self::path(self::document(), 'paths', 'https://yoursite.com/your-callback-url', 'post', 'requestBody', 'content', 'application/json', 'example'));
        $response = $this->postWebhook($example);

        $this->assertSame(200, $response->getStatusCode());
        $message = ($events->of(MessageDeliveredEvent::class)[0] ?? null)?->getMessage();
        $this->assertNotNull($message, 'The documented payload is not read by the SDK.');
        $this->assertSame($example['messageId'], $message->getMessageId());
        $this->assertSame($example['dailyRemaining'], $message->getDailyRemaining());
    }

    public function testEveryDocumentedWebhookStatusHasItsEvent(): void
    {
        $this->bootKernel(['api_key' => 'test-api-key', 'webhook' => ['enabled' => true]]);
        $events = $this->recordEvents(MessageSentEvent::class, MessageDeliveredEvent::class, MessageFailedEvent::class);

        $statuses = self::array(self::path(self::document(), 'components', 'schemas', 'DeliveryWebhookMessage', 'properties', 'status', 'enum'));

        foreach ($statuses as $status) {
            $this->postWebhook(ApiFixtures::webhookPayload(['status' => $status]));
        }

        $this->assertSame($statuses, array_map(static fn (object $event): ?string => $event instanceof DeliveryStatusEvent ? $event->getStatus() : null, $events->events));
    }

    public function testTheDocumentedDailyLimitRefusalIsReported(): void
    {
        $example = self::array(self::path(self::document(), 'paths', '/messages', 'post', 'responses', '429', 'content', 'application/json', 'example'));
        $this->transport()->respond(429, $example);

        try {
            $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);
            $this->fail('The documented refusal was not reported.');
        } catch (DailyLimitExceededException $e) {
            $this->assertSame($example['message'], $e->getMessage());
            $this->assertSame($example['resets_at'], $e->getResetsAt());
        }
    }

    private function client(): SmsClientInterface
    {
        return $this->service('calisero.sms_client', SmsClientInterface::class);
    }

    /**
     * @param list<string>         $expected
     * @param array<string, mixed> $payload
     */
    private function assertSameKeys(array $expected, array $payload): void
    {
        $actual = array_keys($payload);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * @return list<string>
     */
    private static function schemaProperties(string $schema): array
    {
        return array_map('strval', array_keys(self::array(self::path(self::document(), 'components', 'schemas', $schema, 'properties'))));
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        if (null === self::$document) {
            $document = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/resources/openApi/api-v1.json'), true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($document));

            /** @var array<string, mixed> $document */
            self::$document = $document;
        }

        return self::$document;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function path(array $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                throw new \LogicException(\sprintf('The OpenAPI document has no "%s".', implode(' > ', $keys)));
            }

            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function array(mixed $value): array
    {
        \assert(\is_array($value));

        return $value;
    }

    private static function string(mixed $value): string
    {
        \assert(\is_string($value));

        return $value;
    }
}
