<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Integration;

use Calisero\SymfonySms\Tests\IntegrationTestCase;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the console commands through the application, the API answered by the
 * StubTransport.
 */
final class ConsoleCommandsTest extends IntegrationTestCase
{
    public function testSmsTestSendsAMessage(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message(['shortened_urls' => [ApiFixtures::shortenedLink()]])], [
            'X-Trace-Id' => ApiFixtures::TRACE_ID,
            'X-Daily-Limit' => '1000',
            'X-Daily-Remaining' => '872',
        ]);

        $tester = $this->command('calisero:sms:test');
        $tester->execute(['to' => TestPhones::DEFAULT, '--text' => 'Hi']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame(['recipient' => TestPhones::DEFAULT, 'body' => 'Hi'], $this->transport()->lastPayload());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('SMS created', $display);
        $this->assertStringContainsString(ApiFixtures::MESSAGE_ID, $display);
        $this->assertStringContainsString('872 of 1000', $display);
        $this->assertStringContainsString(ApiFixtures::TRACE_ID, $display);
        $this->assertStringContainsString('https://calisero.ro/s/ghJKPV', $display);
    }

    public function testSmsTestSendsEveryOption(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::message()]);

        $this->command('calisero:sms:test')->execute([
            'to' => TestPhones::DEFAULT,
            '--from' => 'CALISERO',
            '--text' => 'Your code is 123456: https://shop.example.test/o/1',
            '--visible-body' => 'Your code is ******',
            '--validity' => '24',
            '--schedule-at' => '2026-12-24 10:00:00',
            '--callback-url' => 'https://hooks.example.test/sms',
            '--shorten-urls' => true,
        ]);

        $this->assertSame([
            'recipient' => TestPhones::DEFAULT,
            'body' => 'Your code is 123456: https://shop.example.test/o/1',
            'visible_body' => 'Your code is ******',
            'validity' => 24,
            'schedule_at' => '2026-12-24 10:00:00',
            'callback_url' => 'https://hooks.example.test/sms',
            'sender' => 'CALISERO',
            'shorten_urls' => true,
        ], $this->transport()->lastPayload());
    }

    public function testSmsTestReportsTheDailyLimitApartFromTheRateLimit(): void
    {
        $this->transport()->respond(429, ApiFixtures::dailyLimitError(), ['Retry-After' => '21600']);

        $tester = $this->command('calisero:sms:test');
        $tester->execute(['to' => TestPhones::DEFAULT]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Daily sending limit reached: This account can send at most 1,000 messages a day.', $display);
        $this->assertStringContainsString('Daily limit: 1000, resets at: 2026-10-01T00:00:00+03:00', $display);
        $this->assertStringContainsString('Trace ID: '.ApiFixtures::TRACE_ID.' (quote it to Calisero support)', $display);
    }

    public function testSmsTestReportsTheRequestRateLimit(): void
    {
        $this->transport()->respond(429, ['message' => 'Too Many Attempts.', 'trace_id' => ApiFixtures::TRACE_ID], ['Retry-After' => '30']);

        $tester = $this->command('calisero:sms:test');
        $tester->execute(['to' => TestPhones::DEFAULT]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Rate limited: Too Many Attempts.', $tester->getDisplay());
        $this->assertStringContainsString('Retry after: 30s', $tester->getDisplay());
    }

    public function testSmsTestListsTheFieldsAtFault(): void
    {
        $this->transport()->respond(422, ApiFixtures::validationError('recipient', 'The recipient format is invalid.'));

        $tester = $this->command('calisero:sms:test');
        $tester->execute(['to' => '0712']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('API validation error: The recipient format is invalid.', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/recipient\s+The recipient format is invalid\./', $tester->getDisplay());
    }

    public function testSmsTestReportsAnOptionOfTheWrongTypeBeforeAnyRequest(): void
    {
        $tester = $this->command('calisero:sms:test');
        $tester->execute(['to' => TestPhones::DEFAULT, '--validity' => 'a day']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('The "validity" parameter of sendSms() must be an integer, string given.', $tester->getDisplay());
        $this->assertSame([], $this->transport()->requests);
    }

    public function testSmsTestReportsAMissingAnswer(): void
    {
        $this->transport()->fail('Could not resolve host: rest.calisero.ro');

        $tester = $this->command('calisero:sms:test');
        $tester->execute(['to' => TestPhones::DEFAULT]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No answer from the API: HTTP request failed: cURL error: Could not resolve host', $tester->getDisplay());
    }

    public function testSmsTestReportsAnInvalidApiKey(): void
    {
        $this->transport()->respond(401, ['message' => 'Unauthenticated.']);

        $tester = $this->command('calisero:sms:test');
        $tester->execute(['to' => TestPhones::DEFAULT]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Auth/permission error: Unauthenticated.', $tester->getDisplay());
    }

    public function testSmsStatusShowsTheMessageAndItsClicks(): void
    {
        $this->transport()->respond(200, ['data' => ApiFixtures::message([
            'status' => 'delivered',
            'delivered_at' => '2025-02-06T10:19:01.000000Z',
            'shortened_urls' => [ApiFixtures::shortenedLink(7, '2025-02-06T11:00:00.000000Z')],
        ])]);

        $tester = $this->command('calisero:sms:status');
        $tester->execute(['id' => ApiFixtures::MESSAGE_ID]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('https://rest.calisero.ro/api/v1/messages/'.ApiFixtures::MESSAGE_ID, (string) $this->transport()->lastRequest()->getUri());
        $this->assertStringContainsString('Status: delivered', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/https:\/\/calisero\.ro\/s\/ghJKPV\s+7\s+2025-02-06T11:00:00/', $tester->getDisplay());
    }

    public function testSmsStatusReportsAMessageNotFound(): void
    {
        $this->transport()->respond(404, ['message' => 'Resource not found!']);

        $tester = $this->command('calisero:sms:status');
        $tester->execute(['id' => 'missing']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Message not found: Resource not found!', $tester->getDisplay());
    }

    public function testSmsStatusRefusesAnEmptyId(): void
    {
        $tester = $this->command('calisero:sms:status');
        $tester->execute(['id' => ' ']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Message id must not be empty', $tester->getDisplay());
        $this->assertSame([], $this->transport()->requests);
    }

    public function testAccountShowsTheDailyLimit(): void
    {
        $this->bootKernel(['api_key' => 'test-api-key', 'account_id' => ApiFixtures::ACCOUNT_ID]);
        $this->transport()->respond(200, ['data' => ApiFixtures::account()]);

        $tester = $this->command('calisero:account');
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Daily Limit\s+1000/', $display);
        $this->assertMatchesRegularExpression('/Daily Remaining\s+873/', $display);
        $this->assertMatchesRegularExpression('/Sent Today\s+127/', $display);
        $this->assertStringContainsString('The daily limit resets at midnight, Romania time.', $display);
    }

    public function testAccountWithoutADailyLimit(): void
    {
        $this->bootKernel(['api_key' => 'test-api-key', 'account_id' => ApiFixtures::ACCOUNT_ID]);
        $this->transport()->respond(200, ['data' => ApiFixtures::account(['daily_limit' => null, 'daily_remaining' => null, 'sandbox' => true])]);

        $tester = $this->command('calisero:account');
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/Daily Limit\s+None/', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/Sandbox\s+Yes/', $tester->getDisplay());
        $this->assertStringNotContainsString('resets at midnight', $tester->getDisplay());
    }

    public function testAccountNeedsTheAccountId(): void
    {
        $tester = $this->command('calisero:account');
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Account ID not configured (calisero.account_id)', $tester->getDisplay());
    }

    public function testVerificationSendSendsACode(): void
    {
        $this->transport()->respond(201, ['data' => ApiFixtures::verification(['template' => 'Your code is {code}', 'brand' => null])], ['X-Daily-Limit' => '1000', 'X-Daily-Remaining' => '871']);

        $tester = $this->command('calisero:verification:send');
        $tester->execute(['to' => TestPhones::DEFAULT, '--template' => 'Your code is {code}', '--expires-in' => '5']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame(['phone' => TestPhones::DEFAULT, 'template' => 'Your code is {code}', 'expires_in' => 5], $this->transport()->lastPayload());
        $this->assertStringContainsString('Verification code sent', $tester->getDisplay());
        $this->assertStringContainsString('871 of 1000', $tester->getDisplay());
    }

    public function testVerificationSendReportsARefusal(): void
    {
        $this->transport()->respond(422, ['message' => 'The brand field is required when template is not present.', 'trace_id' => ApiFixtures::TRACE_ID]);

        $tester = $this->command('calisero:verification:send');
        $tester->execute(['to' => TestPhones::DEFAULT]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('API validation error: The brand field is required when template is not present.', $tester->getDisplay());
        $this->assertStringNotContainsString('Field', $tester->getDisplay());
    }

    public function testSmsStatusReportsAForbiddenRequest(): void
    {
        $this->transport()->respond(403, ['message' => "You don't have permission to access this resource."]);

        $tester = $this->command('calisero:sms:status');
        $tester->execute(['id' => ApiFixtures::MESSAGE_ID]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString("Auth/permission error: You don't have permission to access this resource.", $tester->getDisplay());
    }

    public function testSmsStatusReportsAnyOtherApiError(): void
    {
        $this->transport()->respond(405, ['message' => 'This method is not supported for the requested route.']);

        $tester = $this->command('calisero:sms:status');
        $tester->execute(['id' => ApiFixtures::MESSAGE_ID]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('API error: This method is not supported for the requested route. (status: 405)', $tester->getDisplay());
    }

    public function testVerificationCheckAcceptsTheRightCode(): void
    {
        $this->transport()->respond(200, ['data' => ApiFixtures::verification(['status' => 'verified', 'verified_at' => '2025-11-08T10:10:18.000000Z', 'attempts' => 1])]);

        $tester = $this->command('calisero:verification:check');
        $tester->execute(['to' => TestPhones::DEFAULT, 'code' => 'SBMH0f']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame(['phone' => TestPhones::DEFAULT, 'code' => 'SBMH0f'], $this->transport()->lastPayload());
        $this->assertStringContainsString('Code verified', $tester->getDisplay());
    }

    public function testVerificationCheckFailsForAnUnverifiedCode(): void
    {
        $this->transport()->respond(200, ['data' => ApiFixtures::verification()]);

        $tester = $this->command('calisero:verification:check');
        $tester->execute(['to' => TestPhones::DEFAULT, 'code' => 'SBMH0f']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Code not verified', $tester->getDisplay());
    }

    public function testVerificationCheckReportsAWrongCode(): void
    {
        $this->transport()->respond(422, ApiFixtures::validationError('code', 'The validation code is incorrect.'));

        $tester = $this->command('calisero:verification:check');
        $tester->execute(['to' => TestPhones::DEFAULT, 'code' => 'WRONG1']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('API validation error: The validation code is incorrect.', $tester->getDisplay());
        $this->assertStringContainsString('Trace ID: '.ApiFixtures::TRACE_ID, $tester->getDisplay());
    }

    public function testVerificationCheckReportsAServerError(): void
    {
        $this->transport()->respond(500, ['message' => 'Internal server error!']);

        $tester = $this->command('calisero:verification:check');
        $tester->execute(['to' => TestPhones::DEFAULT, 'code' => 'SBMH0f']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Server error: Internal server error!', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->kernel()))->find($name));
    }
}
