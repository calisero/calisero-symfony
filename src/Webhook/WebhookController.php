<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Webhook;

use Calisero\SymfonySms\Event\CreditCriticalEvent;
use Calisero\SymfonySms\Event\CreditLowEvent;
use Calisero\SymfonySms\Event\DailyLimitLowEvent;
use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Calisero\SymfonySms\Event\MessageFailedEvent;
use Calisero\SymfonySms\Event\MessageSentEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Answers the delivery status callbacks Calisero posts to a message's callback_url, and
 * dispatches them as events.
 *
 * Expected payload:
 * {
 *   "price": 0.0378,
 *   "sender": "CALISERO",
 *   "sentAt": "2025-09-19T11:59:44.000000Z",     // only for sent and delivered
 *   "status": "sent" | "delivered" | "undelivered",
 *   "messageId": "019961d8-3338-700c-be17-10d061f03a5c",
 *   "recipient": "+40742***350",
 *   "scheduleAt": "2025-09-19T11:59:42.000000Z",
 *   "deliveredAt": "2025-09-19T12:00:24.000000Z", // only for delivered
 *   "remainingBalance": 999.43,
 *   "dailyLimit": 1000,                           // null when the account has no daily limit
 *   "dailyRemaining": 588,                        // null when the account has no daily limit
 *   "sentToday": 412
 * }
 *
 * Calisero retries a callback only when the connection fails or the answer takes more
 * than 2 seconds; keep the listeners fast, and hand slow work to Messenger.
 */
final class WebhookController
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        #[\SensitiveParameter]
        private readonly ?string $token = null,
        private readonly ?float $creditLowThreshold = null,
        private readonly ?float $creditCriticalThreshold = null,
        private readonly ?int $dailyLimitLowThreshold = null,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->hasValidToken($request)) {
            return new JsonResponse(['error' => 'Invalid webhook token'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->payload($request);

        if (null === $payload) {
            return new JsonResponse(['error' => 'Invalid webhook payload: expected a JSON object'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->dispatchStatus($payload);
        $this->monitorCredit($payload);
        $this->monitorDailyLimit($payload);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * With a token configured, every callback must carry it as ?token=, compared in
     * constant time.
     */
    private function hasValidToken(Request $request): bool
    {
        if (null === $this->token || '' === $this->token) {
            return true;
        }

        $provided = $request->query->all()['token'] ?? null;

        return \is_string($provided) && '' !== $provided && hash_equals($this->token, $provided);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(Request $request): ?array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // A JSON object; a list or a scalar is not a callback
        if (!\is_array($payload) || ([] !== $payload && array_is_list($payload))) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchStatus(array $payload): void
    {
        $event = match ($payload['status'] ?? null) {
            'sent' => new MessageSentEvent($payload),
            'delivered' => new MessageDeliveredEvent($payload),
            // The API reports a failed delivery as undelivered; failed is accepted from
            // callers that post it themselves
            'undelivered', 'failed' => new MessageFailedEvent($payload),
            default => null,
        };

        if (null !== $event) {
            $this->dispatcher->dispatch($event);
        }
    }

    /**
     * Below the critical threshold only CreditCriticalEvent is dispatched.
     *
     * @param array<string, mixed> $payload
     */
    private function monitorCredit(array $payload): void
    {
        $remaining = $payload['remainingBalance'] ?? null;

        if (!is_numeric($remaining)) {
            return;
        }

        $remaining = (float) $remaining;

        if (null !== $this->creditCriticalThreshold && $remaining <= $this->creditCriticalThreshold) {
            $this->dispatcher->dispatch(new CreditCriticalEvent($remaining));
        } elseif (null !== $this->creditLowThreshold && $remaining <= $this->creditLowThreshold) {
            $this->dispatcher->dispatch(new CreditLowEvent($remaining));
        }
    }

    /**
     * Both dailyLimit and dailyRemaining are null while the account has no daily limit.
     *
     * @param array<string, mixed> $payload
     */
    private function monitorDailyLimit(array $payload): void
    {
        $dailyLimit = self::count($payload['dailyLimit'] ?? null);
        $dailyRemaining = self::count($payload['dailyRemaining'] ?? null);

        if (null === $this->dailyLimitLowThreshold || null === $dailyLimit || null === $dailyRemaining) {
            return;
        }

        if ($dailyRemaining <= $this->dailyLimitLowThreshold) {
            $this->dispatcher->dispatch(new DailyLimitLowEvent($dailyLimit, $dailyRemaining, self::count($payload['sentToday'] ?? null)));
        }
    }

    /**
     * A count: an integer, or a string of digits.
     */
    private static function count(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }
}
