<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Support;

/**
 * What the Calisero API answers, after the examples of its OpenAPI document
 * (resources/openApi/api-v1.json), with the test phone numbers.
 */
final class ApiFixtures
{
    public const MESSAGE_ID = '9e2574e8-3615-4090-9b5a-0fc812079da8';

    public const VERIFICATION_ID = '019a62f1-66b7-7387-a64f-2742c12a2860';

    public const ACCOUNT_ID = '9d0e0bd3-c805-4dd0-bafb-07c5ded0a8e1';

    public const TRACE_ID = '9b80eef1-49d4-4502-85a8-febb68cc11a7';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function message(array $overrides = []): array
    {
        return array_replace([
            'id' => self::MESSAGE_ID,
            'recipient' => TestPhones::DEFAULT,
            'body' => 'Test message',
            'parts' => 1,
            'created_at' => '2025-02-06T10:18:43.000000Z',
            'scheduled_at' => '2025-02-06T10:18:43.000000Z',
            'sent_at' => null,
            'delivered_at' => null,
            'callback_url' => null,
            'status' => 'scheduled',
            'sender' => 'CALISERO',
            'shortened_urls' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function shortenedLink(int $clickCount = 0, ?string $lastClick = null): array
    {
        return [
            'id' => '019adfbb-40a1-71ee-bcb5-8d551b8cfdae',
            'original_link' => 'https://shop.example.test/orders/123/tracking',
            'shortened_link' => 'https://calisero.ro/s/ghJKPV',
            'click_count' => $clickCount,
            'last_click' => $lastClick,
            'created_at' => '2025-12-02T15:43:02.000000Z',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function verification(array $overrides = []): array
    {
        return array_replace([
            'id' => self::VERIFICATION_ID,
            'phone' => TestPhones::DEFAULT,
            'brand' => 'Calisero',
            'status' => 'unverified',
            'template' => null,
            'created_at' => '2025-11-08T10:09:38.000000Z',
            'expires_at' => '2025-11-08T10:12:38.000000Z',
            'verified_at' => null,
            'attempts' => 0,
            'expired' => false,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function account(array $overrides = []): array
    {
        return array_replace([
            'id' => self::ACCOUNT_ID,
            'code' => 'ABC1234',
            'name' => 'My Company',
            'description' => 'Company description',
            'fiscal_code' => 'RO12345678',
            'registry_number' => 'J40/123456/2023',
            'iban' => null,
            'city' => 'Bucharest',
            'state' => 'Bucharest',
            'country' => 'RO',
            'address' => 'Main Street 123',
            'postal_code' => 123456,
            'email' => 'contact@company.example.test',
            'phone' => TestPhones::ALTERNATE,
            'contact_person' => 'John Doe',
            'credit' => 100.50,
            'status' => 'active',
            'sandbox' => false,
            'daily_limit' => 1000,
            'daily_remaining' => 873,
            'sent_today' => 127,
            'created_at' => '2024-09-20T12:48:55.000000Z',
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    public static function messagePage(array $messages, int $page = 1): array
    {
        return [
            'data' => $messages,
            'links' => [
                'first' => 'https://rest.calisero.ro/api/v1/messages?page=1',
                'last' => null,
                'prev' => $page > 1 ? 'https://rest.calisero.ro/api/v1/messages?page='.($page - 1) : null,
                'next' => 'https://rest.calisero.ro/api/v1/messages?page='.($page + 1),
            ],
            'meta' => [
                'current_page' => $page,
                'from' => 1,
                'path' => 'https://rest.calisero.ro/api/v1/messages',
                'per_page' => 50,
                'to' => 50,
            ],
        ];
    }

    /**
     * The 429 of the daily sending limit.
     *
     * @return array<string, mixed>
     */
    public static function dailyLimitError(): array
    {
        return [
            'message' => 'This account can send at most 1,000 messages a day. The limit resets at midnight, Romania time (2026-10-01T00:00:00+03:00). Contact us to raise it.',
            'code' => 'daily_limit_exceeded',
            'daily_limit' => 1000,
            'daily_remaining' => 0,
            'resets_at' => '2026-10-01T00:00:00+03:00',
            'trace_id' => self::TRACE_ID,
        ];
    }

    /**
     * A 422 with field errors.
     *
     * @return array<string, mixed>
     */
    public static function validationError(string $field = 'recipient', string $message = 'The recipient field is required.'): array
    {
        return [
            'message' => $message,
            'errors' => [$field => [$message]],
            'trace_id' => self::TRACE_ID,
        ];
    }

    /**
     * A delivery status callback.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function webhookPayload(array $overrides = []): array
    {
        return array_replace([
            'price' => 0.0378,
            'sender' => 'CALISERO',
            'sentAt' => '2026-01-01T11:59:44.000000Z',
            'status' => 'sent',
            'messageId' => '019961d8-3338-700c-be17-10d061f03a5c',
            'recipient' => TestPhones::DEFAULT,
            'scheduleAt' => '2026-01-01T11:59:42.000000Z',
            'deliveredAt' => null,
            'remainingBalance' => 999.43,
            'dailyLimit' => null,
            'dailyRemaining' => null,
            'sentToday' => 12,
        ], $overrides);
    }

    private function __construct()
    {
    }
}
