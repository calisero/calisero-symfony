<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Notifier;

use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\SmsClient;
use Calisero\SymfonySms\Webhook\CallbackUrlGenerator;
use Symfony\Component\Notifier\Exception\IncompleteDsnException;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport\AbstractTransportFactory;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates the Calisero transport of the Notifier from a DSN:
 *
 *     calisero://API_KEY@default?from=SENDER
 *
 * - API_KEY: your Calisero API key; leave it out (calisero://default) to use calisero.api_key
 * - default: the configured calisero.base_uri; another host (and port, and path) sends there,
 *   e.g. calisero://API_KEY@staging.example.com goes to https://staging.example.com/api/v1
 * - from: the sender ID of the messages that do not set one (optional)
 *
 * The transport applies the bundle's configuration like the SmsClient does: timeouts,
 * User-Agent and the webhook's callback_url.
 */
final class CaliseroTransportFactory extends AbstractTransportFactory
{
    public const SCHEME = 'calisero';

    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly ?CallbackUrlGenerator $callbackUrls = null,
        #[\SensitiveParameter]
        private readonly ?string $defaultApiKey = null,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        parent::__construct($dispatcher);
    }

    public function create(Dsn $dsn): CaliseroTransport
    {
        if (self::SCHEME !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, self::SCHEME, $this->getSupportedSchemes());
        }

        $apiKey = $dsn->getUser() ?? $this->defaultApiKey;

        if (null === $apiKey || '' === $apiKey) {
            throw new IncompleteDsnException('User is not set: put your API key in the DSN (calisero://API_KEY@default), or configure calisero.api_key.', $dsn->getScheme().'://'.$dsn->getHost());
        }

        $from = $dsn->getOption('from');
        $from = \is_string($from) && '' !== $from ? $from : null;

        if ('default' === $dsn->getHost()) {
            $baseUri = $this->clientFactory->getBaseUri();
            $endpoint = self::endpoint($baseUri);
        } else {
            $endpoint = $dsn->getHost().(null !== $dsn->getPort() ? ':'.$dsn->getPort() : '');
            $baseUri = 'https://'.$endpoint.(null !== $dsn->getPath() && '' !== $dsn->getPath() ? $dsn->getPath() : '/api/v1');
        }

        $client = new SmsClient($this->clientFactory->make($apiKey, $baseUri), $this->callbackUrls);

        return new CaliseroTransport($client, $endpoint, $from, $this->dispatcher);
    }

    /**
     * @return list<string>
     */
    protected function getSupportedSchemes(): array
    {
        return [self::SCHEME];
    }

    /**
     * The host (and port) of a base URI, as the transport names itself.
     */
    private static function endpoint(string $baseUri): string
    {
        $host = parse_url($baseUri, \PHP_URL_HOST);
        $port = parse_url($baseUri, \PHP_URL_PORT);

        if (!\is_string($host) || '' === $host) {
            return $baseUri;
        }

        return $host.(\is_int($port) ? ':'.$port : '');
    }
}
