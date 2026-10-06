<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Client;

use Calisero\SymfonySms\SmsClient;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The User-Agent header of every request, as the other Calisero libraries name theirs:
 * the bundle, PHP, Symfony and the platform, e.g.
 * `Calisero-SMS-Symfony/1.0.0 (PHP 8.4.13; Symfony 7.4.0; linux x86_64)`.
 *
 * A request whose User-Agent starts with `Calisero-SMS-Symfony/` comes from this bundle;
 * it cannot be configured.
 *
 * @internal
 */
final class UserAgent
{
    public const PRODUCT = 'Calisero-SMS-Symfony';

    public static function build(): string
    {
        $runtime = ['PHP '.\PHP_VERSION, 'Symfony '.Kernel::VERSION];

        // php_uname() may be disabled on shared hosting; the machine is left out then
        $machine = \function_exists('php_uname') ? strtolower(php_uname('m')) : '';
        $runtime[] = trim(strtolower(\PHP_OS_FAMILY).' '.$machine);

        return \sprintf('%s/%s (%s)', self::PRODUCT, SmsClient::VERSION, implode('; ', $runtime));
    }
}
