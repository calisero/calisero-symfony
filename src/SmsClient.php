<?php

declare(strict_types=1);

namespace Calisero\SymfonySms;

use Calisero\Sms\Dto\Account;
use Calisero\Sms\Dto\CreateMessageRequest;
use Calisero\Sms\Dto\CreateMessageResponse;
use Calisero\Sms\Dto\CreateVerificationRequest;
use Calisero\Sms\Dto\CreateVerificationResponse;
use Calisero\Sms\Dto\GetMessageResponse;
use Calisero\Sms\Dto\GetVerificationResponse;
use Calisero\Sms\Dto\PaginatedMessages;
use Calisero\Sms\Dto\VerificationCheckRequest;
use Calisero\SymfonySms\Client\SdkClient;
use Calisero\SymfonySms\Support\Parameters;
use Calisero\SymfonySms\Support\ScheduleAt;
use Calisero\SymfonySms\Webhook\CallbackUrlGenerator;

/**
 * The bundle's SMS client, the service calisero.sms_client: the Calisero SDK's services
 * behind the methods an application needs most, with the bundle's defaults applied.
 *
 * For everything else (opt-outs, the verifications list...), autowire the SDK's services:
 * MessageService, VerificationService, OptOutService and AccountService.
 */
final class SmsClient implements SmsClientInterface
{
    /**
     * The bundle's version, sent in the User-Agent header; keep it in step with CHANGELOG.md.
     */
    public const VERSION = '1.0.1';

    private const SMS_PARAMETERS = [
        'to', 'text', 'from',
        'visible_body', 'visibleBody',
        'validity',
        'schedule_at', 'scheduleAt',
        'callback_url', 'callbackUrl',
        'shorten_urls', 'shortenUrls',
    ];

    private const VERIFICATION_PARAMETERS = ['to', 'phone', 'brand', 'template', 'expires_in', 'expiresIn'];

    private const CHECK_PARAMETERS = ['to', 'phone', 'code'];

    /**
     * @param ?CallbackUrlGenerator $callbackUrls the callback_url of the messages that do not set their own
     * @param ?string               $accountId    the account of getAccount() and getBalance()
     */
    public function __construct(
        private readonly SdkClient $client,
        private readonly ?CallbackUrlGenerator $callbackUrls = null,
        private readonly ?string $accountId = null,
    ) {
    }

    public function sendSms(array $params): CreateMessageResponse
    {
        $params = new Parameters($params, self::SMS_PARAMETERS, __FUNCTION__);

        $recipient = $params->string('to') ?? '';
        $body = $params->string('text') ?? '';

        if ('' === $recipient || '' === $body) {
            throw new \InvalidArgumentException('Both "to" and "text" parameters are required');
        }

        $scheduleAt = $params->dateTime('schedule_at', 'scheduleAt');

        // A message that sets no callback URL gets the bundle's webhook, when it is enabled
        $callbackUrl = $params->string('callback_url', 'callbackUrl') ?? $this->callbackUrls?->generate();

        return $this->client->messages()->create(new CreateMessageRequest(
            recipient: $recipient,
            body: $body,
            visibleBody: $params->string('visible_body', 'visibleBody'),
            validity: $params->int('validity'),
            scheduleAt: null !== $scheduleAt ? ScheduleAt::format($scheduleAt) : null,
            callbackUrl: $callbackUrl,
            sender: $params->string('from'),
            shortenUrls: $params->bool('shorten_urls', 'shortenUrls'),
        ));
    }

    public function getAccount(): Account
    {
        if (null === $this->accountId || '' === $this->accountId) {
            throw new \RuntimeException('Account ID not configured (calisero.account_id)');
        }

        return $this->client->accounts()->get($this->accountId)->getData();
    }

    public function getBalance(): float
    {
        return $this->getAccount()->getCredit();
    }

    public function getMessageStatus(string $messageId): GetMessageResponse
    {
        return $this->client->messages()->get($messageId);
    }

    public function listMessages(int $page = 1): PaginatedMessages
    {
        return $this->client->messages()->list($page);
    }

    public function deleteMessage(string $messageId): void
    {
        $this->client->messages()->delete($messageId);
    }

    public function sendVerification(array $params): CreateVerificationResponse
    {
        $params = new Parameters($params, self::VERIFICATION_PARAMETERS, __FUNCTION__);

        return $this->client->verifications()->create(new CreateVerificationRequest(
            phone: $params->requiredString('The "to" (or "phone") parameter is required', 'to', 'phone'),
            brand: $params->string('brand'),
            template: $params->string('template'),
            expiresIn: $params->int('expires_in', 'expiresIn'),
        ));
    }

    public function checkVerification(array $params): GetVerificationResponse
    {
        $params = new Parameters($params, self::CHECK_PARAMETERS, __FUNCTION__);

        $phone = $params->requiredString('The "to" (or "phone") parameter is required', 'to', 'phone');
        $code = $params->requiredString('The "code" parameter is required', 'code');

        return $this->client->verifications()->validate(new VerificationCheckRequest($phone, $code));
    }
}
