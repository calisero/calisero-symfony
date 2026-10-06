<?php

declare(strict_types=1);

/*
 * Example: two-factor authentication with the Verifications API
 *
 * Calisero generates the code (6 characters, case-insensitive), sends it and checks it.
 * Either `brand` or `template` (containing {code}) is required; `expires_in` is 1 to 10
 * minutes, 5 by default. A code's SMS counts towards the daily sending limit.
 */

namespace App\Controller;

use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\Sms\Exceptions\NotFoundException;
use Calisero\Sms\Exceptions\RateLimitedException;
use Calisero\Sms\Exceptions\ValidationException;
use Calisero\SymfonySms\SmsClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class TwoFactorController
{
    public function __construct(
        private readonly SmsClientInterface $sms,
    ) {
    }

    #[Route('/2fa/send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $phone = (string) $request->getPayload()->get('phone');

        try {
            $verification = $this->sms->sendVerification([
                'to' => $phone,
                'brand' => 'MyApp',            // or: 'template' => 'Your MyApp code is {code}'
                'expires_in' => 5,
            ])->getData();
        } catch (ValidationException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'fields' => $e->getValidationErrors()], 422);
        } catch (DailyLimitExceededException $e) {
            return new JsonResponse(['error' => 'SMS are unavailable until '.$e->getResetsAt()], 503);
        } catch (RateLimitedException $e) {
            return new JsonResponse(['error' => 'Try again in a moment'], 429, ['Retry-After' => (string) ($e->getRetryAfter() ?? 5)]);
        }

        $request->getSession()->set('2fa_phone', $phone);

        return new JsonResponse(['expires_at' => $verification->getExpiresAt()]);
    }

    #[Route('/2fa/check', methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        $phone = (string) $request->getSession()->get('2fa_phone');

        try {
            $verification = $this->sms->checkVerification([
                'to' => $phone,
                'code' => (string) $request->getPayload()->get('code'),
            ])->getData();
        } catch (ValidationException $e) {
            // 422: a wrong code, an expired one, or too many attempts
            return new JsonResponse(['error' => $e->getMessage()], 422);
        } catch (NotFoundException) {
            return new JsonResponse(['error' => 'Request a new code'], 404);
        }

        if ('verified' !== $verification->getStatus()) {
            return new JsonResponse(['error' => 'The code was not accepted'], 422);
        }

        $request->getSession()->remove('2fa_phone');

        return new JsonResponse(['verified' => true, 'verified_at' => $verification->getVerifiedAt()]);
    }
}
