<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Client;

use Calisero\Sms\Http\BaseHttpClient;
use Calisero\Sms\Services\AccountService;
use Calisero\Sms\Services\MessageService;
use Calisero\Sms\Services\OptOutService;
use Calisero\Sms\Services\VerificationService;
use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\Tests\Doubles\StubTransport;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    private StubTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new StubTransport();
    }

    public function testItBuildsTheSdkServicesOnOneHttpClient(): void
    {
        $client = (new ClientFactory($this->transport))->make('test-api-key');

        $this->assertInstanceOf(MessageService::class, $client->messages());
        $this->assertInstanceOf(VerificationService::class, $client->verifications());
        $this->assertInstanceOf(OptOutService::class, $client->optOuts());
        $this->assertInstanceOf(AccountService::class, $client->accounts());
        $this->assertSame($client->getHttpClient(), $this->property($client->accounts(), AccountService::class, 'httpClient'));
    }

    public function testItAuthenticatesWithTheApiKey(): void
    {
        $this->transport->respond(200, ['data' => ApiFixtures::account()]);

        (new ClientFactory($this->transport))->make('test-api-key')->accounts()->get(ApiFixtures::ACCOUNT_ID);

        $this->assertSame('Bearer test-api-key', $this->transport->lastHeader('Authorization'));
    }

    /**
     * An application without an API key boots: the key is checked by the first request.
     */
    #[DataProvider('missingApiKeys')]
    public function testAMissingApiKeyFailsAtTheFirstRequest(?string $apiKey): void
    {
        $client = (new ClientFactory($this->transport))->make($apiKey);

        try {
            $client->accounts()->get(ApiFixtures::ACCOUNT_ID);
            $this->fail('A request was made without an API key.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Calisero API key is not configured (calisero.api_key)', $e->getMessage());
        }

        $this->assertSame([], $this->transport->requests);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingApiKeys(): iterable
    {
        yield 'null' => [null];
        yield 'an empty string' => [''];
        yield 'blanks' => ['   '];
    }

    #[DataProvider('baseUris')]
    public function testItSendsTheRequestsToTheBaseUri(?string $configured, ?string $given, string $expectedUri): void
    {
        $this->transport->respond(200, ['data' => ApiFixtures::account()]);

        (new ClientFactory($this->transport, $configured))->make('test-api-key', $given)->accounts()->get('acc-1');

        $this->assertSame($expectedUri, (string) $this->transport->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function baseUris(): iterable
    {
        yield 'the production API by default' => [ClientFactory::DEFAULT_BASE_URI, null, 'https://rest.calisero.ro/api/v1/accounts/acc-1'];
        yield 'the configured one, trailing slash trimmed' => ['https://staging.example.test/api/v1/', null, 'https://staging.example.test/api/v1/accounts/acc-1'];
        yield 'one given to make()' => [ClientFactory::DEFAULT_BASE_URI, 'https://other.example.test/v1', 'https://other.example.test/v1/accounts/acc-1'];
        yield 'an empty one falls back to production' => ['', null, 'https://rest.calisero.ro/api/v1/accounts/acc-1'];
        yield 'null falls back to production' => [null, null, 'https://rest.calisero.ro/api/v1/accounts/acc-1'];
    }

    public function testItGivesTheBaseUri(): void
    {
        $this->assertSame('https://staging.example.test/api/v1', (new ClientFactory($this->transport, 'https://staging.example.test/api/v1/'))->getBaseUri());
        $this->assertSame(ClientFactory::DEFAULT_BASE_URI, (new ClientFactory($this->transport, null))->getBaseUri());
    }

    #[DataProvider('timeouts')]
    public function testTheTransportTakesTheTimeoutsInWholeSeconds(mixed $timeout, mixed $connectTimeout, int $expectedTimeout, int $expectedConnectTimeout): void
    {
        $transport = ClientFactory::createTransport($timeout, $connectTimeout);

        $this->assertSame($expectedTimeout, $this->property($transport, BaseHttpClient::class, 'timeout'));
        $this->assertSame($expectedConnectTimeout, $this->property($transport, BaseHttpClient::class, 'connectTimeout'));
    }

    /**
     * @return iterable<string, array{mixed, mixed, int, int}>
     */
    public static function timeouts(): iterable
    {
        yield 'the bundle defaults' => [10.0, 3.0, 10, 3];
        yield 'strings from the environment' => ['20', '5', 20, 5];
        yield 'a fraction is rounded up' => [2.5, 0.2, 3, 1];
        yield 'zero would mean no timeout at all' => [0, 0.0, 1, 1];
        yield 'null falls back to the defaults' => [null, null, 10, 3];
        yield 'not a number falls back to the defaults' => ['soon', 'later', 10, 3];
    }

    /**
     * @param class-string $class
     */
    private function property(object $object, string $class, string $name): mixed
    {
        return (new \ReflectionProperty($class, $name))->getValue($object);
    }
}
