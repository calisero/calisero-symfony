<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Webhook;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The callback_url of the messages that do not set their own: the absolute URL of the
 * bundle's webhook route, with the token, while calisero.webhook.enabled is on.
 *
 * Outside a request (a console command, a Messenger worker) the router builds absolute
 * URLs from framework.router.default_uri: set it to your application's public URL.
 */
final class CallbackUrlGenerator
{
    /**
     * The name of the webhook route.
     */
    public const ROUTE = 'calisero_webhook';

    public function __construct(
        private readonly ?UrlGeneratorInterface $urlGenerator,
        private readonly bool $enabled = false,
        #[\SensitiveParameter]
        private readonly ?string $token = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The webhook's absolute URL, with ?token= when a token is configured; null while the
     * webhook is disabled.
     *
     * @throws \LogicException when the webhook is enabled but its route is not loaded
     */
    public function generate(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        if (null === $this->urlGenerator) {
            throw new \LogicException('calisero.webhook.enabled is on, but the router is not available to build the callback URL.');
        }

        $parameters = null !== $this->token && '' !== $this->token ? ['token' => $this->token] : [];

        try {
            return $this->urlGenerator->generate(self::ROUTE, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException $e) {
            throw new \LogicException(\sprintf('calisero.webhook.enabled is on, but the "%s" route is not loaded: import the bundle routes (resource: ".", type: "%s"), or turn calisero.webhook.enabled off. Calisero would otherwise be sent a callback URL nothing answers.', self::ROUTE, WebhookRouteLoader::TYPE), 0, $e);
        }
    }
}
