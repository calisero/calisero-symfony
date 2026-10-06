<?php

declare(strict_types=1);

/*
 * Example: send an SMS from a service of your application
 *
 * SmsClientInterface is autowired. Keep the message ID: the delivery webhook reports
 * on the message by it.
 */

namespace App\Sms;

use Calisero\Sms\Exceptions\ApiException;
use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\Sms\Exceptions\RateLimitedException;
use Calisero\Sms\Exceptions\ValidationException;
use Calisero\SymfonySms\SmsClientInterface;
use Psr\Log\LoggerInterface;

final class OrderNotifier
{
    public function __construct(
        private readonly SmsClientInterface $sms,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null the message ID, null when the SMS was not sent
     */
    public function orderShipped(string $phone, string $orderNumber): ?string
    {
        try {
            $response = $this->sms->sendSms([
                'to' => $phone,                         // E.164, e.g. +40712345678
                'text' => "Order {$orderNumber} has shipped.",
                // 'from' => 'MyBrand',                 // only once Calisero approved it
            ]);
        } catch (ValidationException $e) {
            // 422: the fields at fault, e.g. ['recipient' => ['The recipient format is invalid.']]
            $this->logger->warning('SMS refused: '.$e->getMessage(), ['errors' => $e->getValidationErrors(), 'trace_id' => $e->getTraceId()]);

            return null;
        } catch (DailyLimitExceededException $e) {
            // 429, before RateLimitedException which it extends: nothing sent until midnight, Romania time
            $this->logger->error('Daily SMS limit reached', ['resets_at' => $e->getResetsAt(), 'trace_id' => $e->getTraceId()]);

            return null;
        } catch (RateLimitedException $e) {
            // 429: more than 240 requests a minute; retry after $e->getRetryAfter() seconds
            $this->logger->warning('SMS rate limited', ['retry_after' => $e->getRetryAfter()]);

            return null;
        } catch (ApiException $e) {
            // Any other refusal, or no answer at all (TransportException)
            $this->logger->error('SMS failed: '.$e->getMessage(), ['status' => $e->getStatusCode(), 'trace_id' => $e->getTraceId()]);

            return null;
        }

        $message = $response->getData();

        $this->logger->info('SMS sent', [
            'message_id' => $message->getId(),
            'parts' => $message->getParts(),
            'status' => $message->getStatus(),                                // scheduled, sent...
            'daily_remaining' => $response->getResponseMeta()->getDailyRemaining(), // null: no daily limit
        ]);

        return $message->getId();
    }
}
