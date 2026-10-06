<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Client;

use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\Client\UserAgent;
use Calisero\SymfonySms\Client\UserAgentHttpClient;
use Calisero\SymfonySms\SmsClient;
use Calisero\SymfonySms\Tests\Doubles\StubTransport;
use Calisero\SymfonySms\Tests\Support\ApiFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Tests the User-Agent header: what it names, and that it reaches the wire in place of
 * the SDK's own.
 */
final class UserAgentTest extends TestCase
{
    public function testItNamesTheBundlePhpSymfonyAndThePlatform(): void
    {
        $this->assertSame(
            'Calisero-SMS-Symfony/'.SmsClient::VERSION
            .' (PHP '.\PHP_VERSION.'; Symfony '.Kernel::VERSION.'; '.strtolower(\PHP_OS_FAMILY.' '.php_uname('m')).')',
            UserAgent::build(),
        );
    }

    /**
     * The other Calisero libraries name theirs the same way, e.g.
     * Calisero-SMS-Laravel/1.3.1 (PHP 8.5.3; Laravel 13.4.0; linux x86_64).
     */
    public function testItHasTheFormatOfTheOtherCaliseroLibraries(): void
    {
        $this->assertMatchesRegularExpression(
            '#^Calisero-SMS-Symfony/\d+\.\d+\.\d+ \(PHP \d+\.\d+\.\d+[^;()]*; Symfony \d+\.\d+\.\d+[^;()]*; [a-z]+( [a-z0-9_]+)?\)$#',
            UserAgent::build(),
        );
    }

    /**
     * The SDK's HttpClient sets its own User-Agent (Calisero-SMS-PHP/<version>) just
     * before handing the request to its transport: the bundle's must replace it.
     */
    public function testItReplacesTheSdkUserAgentOnTheWire(): void
    {
        $transport = (new StubTransport())->respond(200, ['data' => ApiFixtures::account()]);

        (new ClientFactory($transport))->make('test-api-key')->accounts()->get(ApiFixtures::ACCOUNT_ID);

        $this->assertSame([UserAgent::build()], $transport->lastRequest()->getHeaders()['User-Agent'] ?? null);
    }

    public function testTheTransportWrapperGivesTheHeaderItSends(): void
    {
        $this->assertSame('Calisero-SMS-Symfony/9.9.9 (PHP 8.4.13)', (new UserAgentHttpClient(new StubTransport(), 'Calisero-SMS-Symfony/9.9.9 (PHP 8.4.13)'))->getUserAgent());
    }

    public function testTheVersionMatchesTheLatestReleaseOfTheChangelog(): void
    {
        $changelog = (string) file_get_contents(\dirname(__DIR__, 3).'/CHANGELOG.md');
        preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $match);

        $this->assertSame($match[1] ?? 'no release in CHANGELOG.md', SmsClient::VERSION);
    }
}
