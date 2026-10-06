<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Notifier;

use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\Notifier\CaliseroTransportFactory;
use Calisero\SymfonySms\Tests\Doubles\StubTransport;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Notifier\Exception\IncompleteDsnException;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\Dsn;

final class CaliseroTransportFactoryTest extends TestCase
{
    private StubTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new StubTransport();
    }

    #[DataProvider('schemes')]
    public function testItSupportsTheCaliseroScheme(string $dsn, bool $expected): void
    {
        $this->assertSame($expected, $this->factory()->supports(new Dsn($dsn)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function schemes(): iterable
    {
        yield 'calisero' => ['calisero://key@default', true];
        yield 'another provider' => ['twilio://sid:token@default?from=0611223344', false];
    }

    public function testItRefusesAnotherScheme(): void
    {
        $this->expectException(UnsupportedSchemeException::class);

        $this->factory()->create(new Dsn('somethingelse://key@default'));
    }

    #[DataProvider('transportNames')]
    public function testItNamesTheTransportAfterItsEndpointAndSender(string $dsn, string $expected): void
    {
        $this->assertSame($expected, (string) $this->factory()->create(new Dsn($dsn)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function transportNames(): iterable
    {
        yield 'the configured API' => ['calisero://key@default', 'calisero://rest.calisero.test'];
        yield 'with a sender' => ['calisero://key@default?from=CALISERO', 'calisero://rest.calisero.test?from=CALISERO'];
        yield 'another host' => ['calisero://key@staging.example.test', 'calisero://staging.example.test'];
        yield 'another host and port' => ['calisero://key@staging.example.test:8443?from=Shop', 'calisero://staging.example.test:8443?from=Shop'];
    }

    #[DataProvider('endpoints')]
    public function testItSendsToTheHostOfTheDsn(string $dsn, string $expectedUri): void
    {
        $this->send($dsn);

        $this->assertSame($expectedUri, (string) $this->transport->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function endpoints(): iterable
    {
        yield 'default: the configured base URI' => ['calisero://key@default', 'https://rest.calisero.test/api/v1/messages'];
        yield 'another host: its /api/v1' => ['calisero://key@staging.example.test', 'https://staging.example.test/api/v1/messages'];
        yield 'another host, port and path' => ['calisero://key@staging.example.test:8443/sms/v1', 'https://staging.example.test:8443/sms/v1/messages'];
    }

    public function testItAuthenticatesWithTheKeyOfTheDsn(): void
    {
        $this->send('calisero://dsn-key@default');

        $this->assertSame('Bearer dsn-key', $this->transport->lastHeader('Authorization'));
    }

    public function testWithoutAKeyInTheDsnItUsesTheConfiguredOne(): void
    {
        $this->send('calisero://default');

        $this->assertSame('Bearer configured-key', $this->transport->lastHeader('Authorization'));
    }

    public function testItRefusesADsnWithoutAKeyWhenNoneIsConfigured(): void
    {
        $this->expectException(IncompleteDsnException::class);
        $this->expectExceptionMessage('User is not set: put your API key in the DSN (calisero://API_KEY@default), or configure calisero.api_key.');

        $this->factory(null)->create(new Dsn('calisero://default'));
    }

    public function testAConfiguredBaseUriWithoutAHostNamesTheTransportAsItIs(): void
    {
        $factory = new CaliseroTransportFactory(new ClientFactory($this->transport, 'rest.calisero.test/api/v1'), null, 'configured-key');

        $this->assertSame('calisero://rest.calisero.test/api/v1', (string) $factory->create(new Dsn('calisero://default')));
    }

    public function testTheSenderOfTheDsnIsTheDefaultOne(): void
    {
        $this->send('calisero://key@default?from=CALISERO');

        $this->assertSame('CALISERO', $this->transport->lastPayload()['sender'] ?? null);
    }

    private function factory(?string $configuredKey = 'configured-key'): CaliseroTransportFactory
    {
        return new CaliseroTransportFactory(new ClientFactory($this->transport, 'https://rest.calisero.test/api/v1'), null, $configuredKey);
    }

    private function send(string $dsn): void
    {
        $this->transport->respond(201, ['data' => ApiFixtures::message()]);

        $this->factory()->create(new Dsn($dsn))->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));
    }
}
