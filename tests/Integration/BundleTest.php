<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Integration;

use Calisero\Sms\Http\BaseHttpClient;
use Calisero\SymfonySms\CaliseroSmsBundle;
use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\Client\UserAgent;
use Calisero\SymfonySms\SmsClient;
use Calisero\SymfonySms\Tests\Fixtures\AutowiredServices;
use Calisero\SymfonySms\Tests\IntegrationTestCase;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tests the bundle inside an application: its configuration, its services and what it
 * registers in the framework.
 */
final class BundleTest extends IntegrationTestCase
{
    public function testTheDefaultConfiguration(): void
    {
        $this->assertSame([
            'api_key' => null,
            'base_uri' => 'https://rest.calisero.ro/api/v1',
            'account_id' => null,
            'timeout' => 10.0,
            'connect_timeout' => 3.0,
            'webhook' => [
                'enabled' => false,
                'path' => '/calisero/webhook',
                'token' => null,
            ],
            'credit' => [
                'low_threshold' => null,
                'critical_threshold' => null,
            ],
            'daily_limit' => [
                'low_threshold' => null,
            ],
        ], self::processConfiguration([]));
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testItRefusesAnInvalidConfiguration(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        self::processConfiguration($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'a negative timeout' => [['timeout' => -1], 'calisero.timeout'];
        yield 'an empty base URI' => [['base_uri' => ''], 'The path "calisero.base_uri" cannot contain an empty value'];
        yield 'a webhook flag that is not a boolean' => [['webhook' => ['enabled' => 'yes']], 'Invalid type for path "calisero.webhook.enabled". Expected "bool"'];
        yield 'a negative daily limit threshold' => [['daily_limit' => ['low_threshold' => -5]], 'calisero.daily_limit.low_threshold'];
        yield 'an unknown option' => [['sender' => 'CALISERO'], 'Unrecognized option "sender" under "calisero"'];
        // The User-Agent names the bundle alone, as the other Calisero libraries' do
        yield 'a User-Agent setting' => [['app_info' => ['name' => 'MyShop']], 'Unrecognized option "app_info" under "calisero"'];
    }

    public function testItsServicesCanBeAutowired(): void
    {
        $services = $this->service(AutowiredServices::class, AutowiredServices::class);

        $this->assertInstanceOf(SmsClient::class, $services->smsClient);
        $this->assertSame($services->sdkClient->messages(), $services->messages);
        $this->assertSame($services->sdkClient->verifications(), $services->verifications);
        $this->assertSame($services->sdkClient->optOuts(), $services->optOuts);
        $this->assertSame($services->sdkClient->accounts(), $services->accounts);
    }

    public function testTheClientUsesTheConfiguration(): void
    {
        $this->bootKernel(['api_key' => 'live-key', 'base_uri' => 'https://staging.example.test/api/v1/', 'account_id' => 'acc-1']);
        $this->transport()->respond(200, ['data' => ApiFixtures::account()]);

        $this->service(AutowiredServices::class, AutowiredServices::class)->smsClient->getAccount();

        $this->assertSame('https://staging.example.test/api/v1/accounts/acc-1', (string) $this->transport()->lastRequest()->getUri());
        $this->assertSame('Bearer live-key', $this->transport()->lastHeader('Authorization'));
        $this->assertSame(UserAgent::build(), $this->transport()->lastHeader('User-Agent'));
    }

    public function testTheTimeoutsReachTheCurlTransport(): void
    {
        $this->bootKernel(['api_key' => 'live-key', 'timeout' => 2.5, 'connect_timeout' => 1], stubTransport: false);

        $factory = $this->service(AutowiredServices::class, AutowiredServices::class)->clientFactory;
        $transport = (new \ReflectionProperty(ClientFactory::class, 'transport'))->getValue($factory);

        $this->assertInstanceOf(BaseHttpClient::class, $transport);
        $this->assertSame(3, (new \ReflectionProperty(BaseHttpClient::class, 'timeout'))->getValue($transport));
        $this->assertSame(1, (new \ReflectionProperty(BaseHttpClient::class, 'connectTimeout'))->getValue($transport));
    }

    /**
     * The configuration may come from environment variables, which are only read when the
     * application runs: the bundle must never read them while the container is built.
     */
    public function testEnvironmentVariablesConfigureTheBundle(): void
    {
        $this->setEnvironmentVariables([
            'CALISERO_TEST_API_KEY' => 'env-key',
            'CALISERO_TEST_ACCOUNT_ID' => 'acc-env',
            'CALISERO_TEST_WEBHOOK' => 'true',
            'CALISERO_TEST_WEBHOOK_TOKEN' => 'env-token',
        ]);

        $this->bootKernel([
            'api_key' => '%env(CALISERO_TEST_API_KEY)%',
            'account_id' => '%env(CALISERO_TEST_ACCOUNT_ID)%',
            'webhook' => [
                'enabled' => '%env(bool:CALISERO_TEST_WEBHOOK)%',
                'token' => '%env(CALISERO_TEST_WEBHOOK_TOKEN)%',
            ],
        ]);

        $client = $this->service(AutowiredServices::class, AutowiredServices::class)->smsClient;

        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);
        $client->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);
        $this->assertSame('Bearer env-key', $this->transport()->lastHeader('Authorization'));
        $this->assertSame('https://app.example.test/calisero/webhook?token=env-token', $this->transport()->lastPayload()['callback_url'] ?? null);

        $this->transport()->respond(200, ['data' => ApiFixtures::account()]);
        $client->getAccount();
        $this->assertSame('https://rest.calisero.ro/api/v1/accounts/acc-env', (string) $this->transport()->lastRequest()->getUri());

        $this->assertSame(200, $this->postWebhook(ApiFixtures::webhookPayload(), '/calisero/webhook?token=env-token')->getStatusCode());
    }

    public function testTheTimeoutsCanComeFromEnvironmentVariables(): void
    {
        $this->setEnvironmentVariables(['CALISERO_TEST_TIMEOUT' => '4.2', 'CALISERO_TEST_CONNECT_TIMEOUT' => '2']);

        $this->bootKernel([
            'api_key' => 'live-key',
            'timeout' => '%env(float:CALISERO_TEST_TIMEOUT)%',
            'connect_timeout' => '%env(float:CALISERO_TEST_CONNECT_TIMEOUT)%',
        ], stubTransport: false);

        $factory = $this->service(AutowiredServices::class, AutowiredServices::class)->clientFactory;
        $transport = (new \ReflectionProperty(ClientFactory::class, 'transport'))->getValue($factory);

        $this->assertInstanceOf(BaseHttpClient::class, $transport);
        $this->assertSame(5, (new \ReflectionProperty(BaseHttpClient::class, 'timeout'))->getValue($transport));
        $this->assertSame(2, (new \ReflectionProperty(BaseHttpClient::class, 'connectTimeout'))->getValue($transport));
    }

    public function testANullThresholdDisablesTheMonitoring(): void
    {
        $config = self::processConfiguration(['credit' => ['low_threshold' => null, 'critical_threshold' => 100], 'daily_limit' => ['low_threshold' => null]]);

        $credit = $config['credit'];
        \assert(\is_array($credit));
        ksort($credit);

        // A null threshold is dropped, then put back as the default: null
        $this->assertEquals(['critical_threshold' => 100, 'low_threshold' => null], $credit);
        $this->assertSame(['low_threshold' => null], $config['daily_limit']);
    }

    public function testItRegistersTheConsoleCommands(): void
    {
        $application = new Application($this->kernel());

        foreach (['calisero:sms:test', 'calisero:sms:status', 'calisero:account', 'calisero:verification:send', 'calisero:verification:check'] as $name) {
            $this->assertTrue($application->has($name), $name);
        }
    }

    public function testItRegistersTheRomanianTranslationsOfItsConstraints(): void
    {
        $translator = $this->service('test.translator', TranslatorInterface::class);

        $this->assertSame(
            'Această valoare nu este un număr de telefon valid în format E.164.',
            $translator->trans('This value is not a valid E.164 phone number.', [], 'validators', 'ro'),
        );
        $this->assertSame(
            'Această valoare trebuie să aibă între 3 și 11 caractere.',
            $translator->trans('This value should be between {{ min }} and {{ max }} characters long.', ['{{ min }}' => '3', '{{ max }}' => '11'], 'validators', 'ro'),
        );
        $this->assertSame(
            'Această valoare poate conține doar litere, cifre, spații, liniuțe și puncte.',
            $translator->trans('This value may only contain letters, digits, spaces, hyphens and dots.', [], 'validators', 'ro'),
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function processConfiguration(array $config): array
    {
        $extension = (new CaliseroSmsBundle())->getContainerExtension();
        \assert($extension instanceof ConfigurationExtensionInterface);

        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        \assert($configuration instanceof ConfigurationInterface);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
