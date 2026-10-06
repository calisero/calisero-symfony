<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit;

use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\Sms\Exceptions\NotFoundException;
use Calisero\Sms\Exceptions\TransportException;
use Calisero\Sms\Exceptions\ValidationException;
use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\SmsClient;
use Calisero\SymfonySms\Tests\Doubles\StubTransport;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use Calisero\SymfonySms\Webhook\CallbackUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests the bundle's client over the SDK, down to what goes over the wire: argument
 * mapping, parameter aliases and checks, the webhook's callback_url, delegation.
 */
final class SmsClientTest extends TestCase
{
    private StubTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new StubTransport();
    }

    public function testItSendsTheRequiredParameters(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);

        $request = $this->transport->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://rest.calisero.test/api/v1/messages', (string) $request->getUri());
        $this->assertSame(['recipient' => TestPhones::DEFAULT, 'body' => 'Hello'], $this->transport->lastPayload());
    }

    public function testItReturnsTheSdkResponseWithWhatItsHeadersSaid(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message(['shortened_urls' => [ApiFixtures::shortenedLink()]])], [
            'X-Trace-Id' => ApiFixtures::TRACE_ID,
            'X-Daily-Limit' => '1000',
            'X-Daily-Remaining' => '872',
        ]);

        $response = $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);

        $this->assertSame(ApiFixtures::MESSAGE_ID, $response->getData()->getId());
        $this->assertSame('https://calisero.ro/s/ghJKPV', $response->getData()->getShortenedUrls()[0]->getShortenedLink());
        $this->assertSame(ApiFixtures::TRACE_ID, $response->getResponseMeta()->getTraceId());
        $this->assertSame(1000, $response->getResponseMeta()->getDailyLimit());
        $this->assertSame(872, $response->getResponseMeta()->getDailyRemaining());
    }

    public function testItSendsEveryOption(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client()->sendSms([
            'to' => TestPhones::DEFAULT,
            'text' => 'Your code is 123456. Track: https://shop.example.test/o/1',
            'from' => 'CALISERO',
            'visible_body' => 'Your code is ******.',
            'validity' => 24,
            'schedule_at' => '2026-12-24 10:00:00',
            'callback_url' => 'https://hooks.example.test/sms',
            'shorten_urls' => true,
        ]);

        $this->assertSame([
            'recipient' => TestPhones::DEFAULT,
            'body' => 'Your code is 123456. Track: https://shop.example.test/o/1',
            'visible_body' => 'Your code is ******.',
            'validity' => 24,
            'schedule_at' => '2026-12-24 10:00:00',
            'callback_url' => 'https://hooks.example.test/sms',
            'sender' => 'CALISERO',
            'shorten_urls' => true,
        ], $this->transport->lastPayload());
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('optionalParameterAliases')]
    public function testItAcceptsBothNamingStylesForOptionalParameters(array $params, string $apiField, mixed $expected): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello'] + $params);

        $this->assertSame($expected, $this->transport->lastPayload()[$apiField] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, mixed}>
     */
    public static function optionalParameterAliases(): iterable
    {
        yield 'visible_body' => [['visible_body' => 'Shown'], 'visible_body', 'Shown'];
        yield 'visibleBody' => [['visibleBody' => 'Shown'], 'visible_body', 'Shown'];
        yield 'schedule_at' => [['schedule_at' => '2026-01-01 10:00:00'], 'schedule_at', '2026-01-01 10:00:00'];
        yield 'scheduleAt' => [['scheduleAt' => '2026-01-01 10:00:00'], 'schedule_at', '2026-01-01 10:00:00'];
        yield 'callback_url' => [['callback_url' => 'https://hooks.example.test/cb'], 'callback_url', 'https://hooks.example.test/cb'];
        yield 'callbackUrl' => [['callbackUrl' => 'https://hooks.example.test/cb'], 'callback_url', 'https://hooks.example.test/cb'];
        yield 'shorten_urls' => [['shorten_urls' => true], 'shorten_urls', true];
        yield 'shortenUrls' => [['shortenUrls' => true], 'shorten_urls', true];
        yield 'snake_case wins over camelCase' => [['visible_body' => 'Snake', 'visibleBody' => 'Camel'], 'visible_body', 'Snake'];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('missingRequiredParameters')]
    public function testItRejectsMissingRequiredParameters(array $params): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both "to" and "text" parameters are required');

        $this->client()->sendSms($params);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingRequiredParameters(): iterable
    {
        yield 'no recipient' => [['text' => 'Hello']];
        yield 'empty recipient' => [['to' => '', 'text' => 'Hello']];
        yield 'no body' => [['to' => TestPhones::DEFAULT]];
        yield 'empty body' => [['to' => TestPhones::DEFAULT, 'text' => '']];
        yield 'neither' => [[]];
    }

    /**
     * A phone number value object of the application, or a number from a form.
     */
    public function testItTakesStringableAndNumericValues(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $phone = new class implements \Stringable {
            public function __toString(): string
            {
                return TestPhones::DEFAULT;
            }
        };
        $this->client()->sendSms(['to' => $phone, 'text' => 123456]);

        $this->assertSame(['recipient' => TestPhones::DEFAULT, 'body' => '123456'], $this->transport->lastPayload());
    }

    /**
     * A typo would otherwise be dropped without a word, and the message sent without it.
     */
    public function testItRejectsAnUnknownParameter(): void
    {
        try {
            $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Later', 'sheduleAt' => '2026-12-24 10:00:00']);
            $this->fail('An unknown parameter was accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Unknown parameter "sheduleAt" for sendSms(); the accepted ones are: to, text, from,', $e->getMessage());
        }

        $this->assertSame([], $this->transport->requests);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('parametersOfTheWrongType')]
    public function testItRejectsAParameterOfTheWrongType(array $params, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello'] + $params);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function parametersOfTheWrongType(): iterable
    {
        yield 'a validity that is not a number' => [['validity' => 'two days'], 'The "validity" parameter of sendSms() must be an integer, string given.'];
        yield 'a fractional validity' => [['validity' => 1.5], 'The "validity" parameter of sendSms() must be an integer, float given.'];
        yield 'a sender that is not a string' => [['from' => ['CALISERO']], 'The "from" parameter of sendSms() must be a string, array given.'];
        yield 'shorten_urls that is not a boolean' => [['shorten_urls' => 'maybe'], 'The "shorten_urls" parameter of sendSms() must be a boolean, string given.'];
        yield 'a schedule that is a timestamp' => [['scheduleAt' => 1790000000], 'The "scheduleAt" parameter of sendSms() must be a \DateTimeInterface or a "Y-m-d H:i:s" string, int given.'];
    }

    /**
     * Values from a form or the environment arrive as strings: "false" must not turn
     * into true, as a (bool) cast would make it.
     */
    #[DataProvider('shortenUrlsValues')]
    public function testItReadsShortenUrlsAsABoolean(mixed $value, bool $expected): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'https://example.test', 'shorten_urls' => $value]);

        $this->assertSame($expected, $this->transport->lastPayload()['shorten_urls'] ?? null);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function shortenUrlsValues(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'the string "1"' => ['1', true];
        yield 'the string "on"' => ['on', true];
        yield 'the string "false"' => ['false', false];
        yield 'the string "0"' => ['0', false];
        yield 'the integer 1' => [1, true];
    }

    public function testItLeavesUrlShorteningToTheApiByDefault(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);

        $this->assertArrayNotHasKey('shorten_urls', $this->transport->lastPayload());
    }

    public function testItTakesAValidityGivenAsAString(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello', 'validity' => '48']);

        $this->assertSame(48, $this->transport->lastPayload()['validity'] ?? null);
    }

    /**
     * The API reads schedule_at as a 'Y-m-d H:i:s' in Romania time and refuses ISO 8601;
     * a date-time object is converted, daylight saving time included.
     */
    #[DataProvider('scheduleDates')]
    public function testItConvertsAScheduleDateTimeToRomaniaTime(\DateTimeInterface $scheduleAt, string $expected): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Later', 'schedule_at' => $scheduleAt]);

        $this->assertSame($expected, $this->transport->lastPayload()['schedule_at'] ?? null);
    }

    /**
     * @return iterable<string, array{\DateTimeInterface, string}>
     */
    public static function scheduleDates(): iterable
    {
        yield 'UTC in winter (UTC+2)' => [new \DateTimeImmutable('2026-01-15 08:00:00', new \DateTimeZone('UTC')), '2026-01-15 10:00:00'];
        yield 'UTC in summer (UTC+3)' => [new \DateTimeImmutable('2026-07-15 08:00:00', new \DateTimeZone('UTC')), '2026-07-15 11:00:00'];
        yield 'already Romania time' => [new \DateTime('2026-03-01 09:30:00', new \DateTimeZone('Europe/Bucharest')), '2026-03-01 09:30:00'];
        yield 'another time zone, across midnight' => [new \DateTimeImmutable('2026-12-24 17:00:00', new \DateTimeZone('America/New_York')), '2026-12-25 00:00:00'];
    }

    public function testItSendsTheWebhookAsTheCallbackUrl(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client(self::callbackUrls(token: 's3cr+t/1'))->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);

        $this->assertSame('https://app.example.test/calisero/webhook?token=s3cr%2Bt/1', $this->transport->lastPayload()['callback_url'] ?? null);
    }

    public function testItSendsTheWebhookWithoutATokenWhenNoneIsConfigured(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client(self::callbackUrls())->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);

        $this->assertSame('https://app.example.test/calisero/webhook', $this->transport->lastPayload()['callback_url'] ?? null);
    }

    #[DataProvider('explicitCallbackUrls')]
    public function testAnExplicitCallbackUrlWinsOverTheWebhook(string $key): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client(self::callbackUrls(token: 'secret'))->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello', $key => 'https://hooks.example.test/own']);

        $this->assertSame('https://hooks.example.test/own', $this->transport->lastPayload()['callback_url'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function explicitCallbackUrls(): iterable
    {
        yield 'callback_url' => ['callback_url'];
        yield 'callbackUrl' => ['callbackUrl'];
    }

    public function testItSendsNoCallbackUrlWhileTheWebhookIsDisabled(): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->client(self::callbackUrls(enabled: false))->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);

        $this->assertArrayNotHasKey('callback_url', $this->transport->lastPayload());
    }

    /**
     * Calisero would otherwise be sent a callback URL nothing answers.
     */
    public function testItRefusesToSendWhileTheWebhookRouteIsMissing(): void
    {
        try {
            $this->client(self::callbackUrls(withRoute: false))->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);
            $this->fail('The message was sent without its callback URL.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('the "calisero_webhook" route is not loaded', $e->getMessage());
        }

        $this->assertSame([], $this->transport->requests);
    }

    public function testItReturnsTheConfiguredAccount(): void
    {
        $this->transport->respond(200, ['data' => ApiFixtures::account()]);

        $account = $this->client()->getAccount();

        $this->assertSame('GET', $this->transport->lastRequest()->getMethod());
        $this->assertSame('https://rest.calisero.test/api/v1/accounts/'.ApiFixtures::ACCOUNT_ID, (string) $this->transport->lastRequest()->getUri());
        $this->assertSame(1000, $account->getDailyLimit());
        $this->assertSame(873, $account->getDailyRemaining());
        $this->assertSame(127, $account->getSentToday());
    }

    public function testItReturnsTheAccountCreditAsTheBalance(): void
    {
        $this->transport->respond(200, ['data' => ApiFixtures::account(['credit' => 123.45])]);

        $this->assertSame(123.45, $this->client()->getBalance());
    }

    #[DataProvider('missingAccountIds')]
    public function testItRefusesToReadTheAccountWithoutAnAccountId(?string $accountId, string $method): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Account ID not configured (calisero.account_id)');

        $this->client(null, $accountId)->{$method}();
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function missingAccountIds(): iterable
    {
        yield 'getAccount(), null' => [null, 'getAccount'];
        yield 'getAccount(), empty' => ['', 'getAccount'];
        yield 'getBalance(), null' => [null, 'getBalance'];
    }

    public function testItGetsAMessageWithItsClicks(): void
    {
        $this->transport->respond(200, ['data' => ApiFixtures::message(['status' => 'delivered', 'shortened_urls' => [ApiFixtures::shortenedLink(3, '2026-01-01T12:00:00.000000Z')]])]);

        $message = $this->client()->getMessageStatus(ApiFixtures::MESSAGE_ID)->getData();

        $this->assertSame('https://rest.calisero.test/api/v1/messages/'.ApiFixtures::MESSAGE_ID, (string) $this->transport->lastRequest()->getUri());
        $this->assertSame('delivered', $message->getStatus());
        $this->assertSame(3, $message->getShortenedUrls()[0]->getClickCount());
    }

    #[DataProvider('pages')]
    public function testItListsTheMessages(int $page, string $expectedUri): void
    {
        $this->transport->respond(200, ApiFixtures::messagePage([ApiFixtures::message()], $page));

        $messages = $this->client()->listMessages($page);

        $this->assertSame($expectedUri, (string) $this->transport->lastRequest()->getUri());
        $this->assertSame($page, $messages->getMeta()->getCurrentPage());
        $this->assertCount(1, $messages->getData());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function pages(): iterable
    {
        yield 'the first page' => [1, 'https://rest.calisero.test/api/v1/messages'];
        yield 'a later page' => [3, 'https://rest.calisero.test/api/v1/messages?page=3'];
    }

    public function testItDeletesAMessage(): void
    {
        $this->transport->respond(204);

        $this->client()->deleteMessage(ApiFixtures::MESSAGE_ID);

        $this->assertSame('DELETE', $this->transport->lastRequest()->getMethod());
        $this->assertSame('https://rest.calisero.test/api/v1/messages/'.ApiFixtures::MESSAGE_ID, (string) $this->transport->lastRequest()->getUri());
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $expectedPayload
     */
    #[DataProvider('verifications')]
    public function testItSendsAVerification(array $params, array $expectedPayload): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::verification()], ['X-Daily-Limit' => '1000', 'X-Daily-Remaining' => '871']);

        $response = $this->client()->sendVerification($params);

        $this->assertSame('https://rest.calisero.test/api/v1/verifications', (string) $this->transport->lastRequest()->getUri());
        $this->assertSame($expectedPayload, $this->transport->lastPayload());
        $this->assertSame(ApiFixtures::VERIFICATION_ID, $response->getData()->getId());
        $this->assertSame(871, $response->getResponseMeta()->getDailyRemaining());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function verifications(): iterable
    {
        yield 'with a brand' => [['to' => TestPhones::DEFAULT, 'brand' => 'MyApp'], ['phone' => TestPhones::DEFAULT, 'brand' => 'MyApp']];
        yield 'with a template, expiring' => [
            ['phone' => TestPhones::DEFAULT, 'template' => 'Your code is {code}', 'expires_in' => 5],
            ['phone' => TestPhones::DEFAULT, 'template' => 'Your code is {code}', 'expires_in' => 5],
        ];
        yield 'expiresIn, as a string' => [['to' => TestPhones::DEFAULT, 'brand' => 'MyApp', 'expiresIn' => '3'], ['phone' => TestPhones::DEFAULT, 'brand' => 'MyApp', 'expires_in' => 3]];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('verificationsWithoutAPhone')]
    public function testItRejectsAVerificationWithoutAPhone(string $method, array $params): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "to" (or "phone") parameter is required');

        $this->client()->{$method}($params);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function verificationsWithoutAPhone(): iterable
    {
        yield 'send, no phone' => ['sendVerification', ['brand' => 'MyApp']];
        yield 'send, empty phone' => ['sendVerification', ['to' => '', 'brand' => 'MyApp']];
        yield 'check, no phone' => ['checkVerification', ['code' => 'ABC123']];
    }

    public function testItRejectsAnUnknownVerificationParameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown parameter "expires" for sendVerification()');

        $this->client()->sendVerification(['to' => TestPhones::DEFAULT, 'brand' => 'MyApp', 'expires' => 5]);
    }

    public function testItChecksAVerificationCode(): void
    {
        $this->transport->respond(200, ['data' => ApiFixtures::verification(['status' => 'verified', 'verified_at' => '2025-11-08T10:10:18.000000Z', 'attempts' => 1])]);

        $verification = $this->client()->checkVerification(['to' => TestPhones::DEFAULT, 'code' => 'SBMH0f'])->getData();

        $this->assertSame('PUT', $this->transport->lastRequest()->getMethod());
        $this->assertSame('https://rest.calisero.test/api/v1/verifications/validate', (string) $this->transport->lastRequest()->getUri());
        $this->assertSame(['phone' => TestPhones::DEFAULT, 'code' => 'SBMH0f'], $this->transport->lastPayload());
        $this->assertSame('verified', $verification->getStatus());
    }

    public function testItRejectsAVerificationCheckWithoutACode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "code" parameter is required');

        $this->client()->checkVerification(['to' => TestPhones::DEFAULT]);
    }

    public function testTheDailyLimitRefusalReachesTheCaller(): void
    {
        $this->transport->respond(429, ApiFixtures::dailyLimitError(), ['Retry-After' => '21600', 'X-Daily-Limit' => '1000', 'X-Daily-Remaining' => '0']);

        try {
            $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);
            $this->fail('The daily limit refusal was not reported.');
        } catch (DailyLimitExceededException $e) {
            $this->assertSame(1000, $e->getDailyLimit());
            $this->assertSame('2026-10-01T00:00:00+03:00', $e->getResetsAt());
            $this->assertSame(21600, $e->getRetryAfter());
            $this->assertSame(ApiFixtures::TRACE_ID, $e->getTraceId());
        }
    }

    public function testTheValidationErrorsReachTheCaller(): void
    {
        $this->transport->respond(422, ApiFixtures::validationError('code', 'The validation code is incorrect.'));

        try {
            $this->client()->checkVerification(['to' => TestPhones::DEFAULT, 'code' => 'WRONG1']);
            $this->fail('The validation error was not reported.');
        } catch (ValidationException $e) {
            $this->assertSame('The validation code is incorrect.', $e->getMessage());
            $this->assertSame(['code' => ['The validation code is incorrect.']], $e->getValidationErrors());
        }
    }

    public function testAMissingMessageIsReportedAsNotFound(): void
    {
        $this->transport->respond(404, ['message' => 'Resource not found!']);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Resource not found!');

        $this->client()->getMessageStatus('missing');
    }

    public function testARequestWithoutAnAnswerIsReportedAsATransportFailure(): void
    {
        $this->transport->fail('Operation timed out after 10001 milliseconds');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Operation timed out');

        $this->client()->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);
    }

    private function client(?CallbackUrlGenerator $callbackUrls = null, ?string $accountId = ApiFixtures::ACCOUNT_ID): SmsClient
    {
        return new SmsClient((new ClientFactory($this->transport, 'https://rest.calisero.test/api/v1'))->make('test-api-key'), $callbackUrls, $accountId);
    }

    private static function callbackUrls(bool $enabled = true, ?string $token = null, bool $withRoute = true): CallbackUrlGenerator
    {
        $routes = new RouteCollection();

        if ($withRoute) {
            $routes->add(CallbackUrlGenerator::ROUTE, new Route('/calisero/webhook', methods: ['POST']));
        }

        return new CallbackUrlGenerator(new UrlGenerator($routes, RequestContext::fromUri('https://app.example.test')), $enabled, $token);
    }
}
