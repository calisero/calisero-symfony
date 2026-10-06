<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Integration;

use Calisero\SymfonySms\Event\CreditCriticalEvent;
use Calisero\SymfonySms\Event\DailyLimitLowEvent;
use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Calisero\SymfonySms\SmsClientInterface;
use Calisero\SymfonySms\Tests\IntegrationTestCase;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

/**
 * Boots the application on the configuration files of examples/config, as the README
 * tells to copy them: the documentation cannot drift from the bundle.
 */
final class DocumentedConfigurationTest extends IntegrationTestCase
{
    private const VARIABLES = [
        'CALISERO_API_KEY' => 'documented-key',
        'CALISERO_ACCOUNT_ID' => ApiFixtures::ACCOUNT_ID,
        'CALISERO_WEBHOOK_ENABLED' => 'true',
        'CALISERO_WEBHOOK_TOKEN' => 'documented-token',
        'CALISERO_DSN' => 'calisero://default?from=MyBrand',
    ];

    protected function setUp(): void
    {
        $this->setEnvironmentVariables(self::VARIABLES);

        $examples = \dirname(__DIR__, 2).'/examples/config';

        $this->bootKernel([], ['router' => ['resource' => $examples.'/routes/calisero.yaml']], configFiles: [
            $examples.'/packages/calisero.yaml',
            $examples.'/packages/notifier.yaml',
        ]);
    }

    public function testTheClientUsesTheDocumentedConfiguration(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $this->service('calisero.sms_client', SmsClientInterface::class)->sendSms(['to' => TestPhones::DEFAULT, 'text' => 'Hello']);

        $this->assertSame('Bearer documented-key', $this->transport()->lastHeader('Authorization'));
        $this->assertSame('https://app.example.test/calisero/webhook?token=documented-token', $this->transport()->lastPayload()['callback_url'] ?? null);
    }

    public function testTheAccountIdComesFromTheEnvironment(): void
    {
        $this->transport()->respond(200, ['data' => ApiFixtures::account()]);

        $this->service('calisero.sms_client', SmsClientInterface::class)->getAccount();

        $this->assertSame('https://rest.calisero.ro/api/v1/accounts/'.ApiFixtures::ACCOUNT_ID, (string) $this->transport()->lastRequest()->getUri());
    }

    public function testTheTexterUsesTheDocumentedDsn(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $this->service('test.texter', TexterInterface::class)->send(new SmsMessage(TestPhones::DEFAULT, 'Hello'));

        $this->assertSame('Bearer documented-key', $this->transport()->lastHeader('Authorization'));
        $this->assertSame('MyBrand', $this->transport()->lastPayload()['sender'] ?? null);
    }

    public function testTheWebhookAnswersAtTheDocumentedRouteWithTheDocumentedThresholds(): void
    {
        $events = $this->recordEvents(MessageDeliveredEvent::class, CreditCriticalEvent::class, DailyLimitLowEvent::class);

        $response = $this->postWebhook(ApiFixtures::webhookPayload(['status' => 'delivered', 'remainingBalance' => 99.0, 'dailyLimit' => 1000, 'dailyRemaining' => 100]), '/calisero/webhook?token=documented-token');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([MessageDeliveredEvent::class, CreditCriticalEvent::class, DailyLimitLowEvent::class], $events->classes());
    }
}
