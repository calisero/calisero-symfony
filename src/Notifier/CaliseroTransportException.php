<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Notifier;

use Calisero\Sms\Exceptions\ApiException;
use Symfony\Component\Notifier\Exception\RuntimeException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;

/**
 * The Calisero API refused an SMS sent through the Notifier, or did not answer.
 *
 * The SDK's exception is the previous one, also returned by getApiException(): tell a
 * DailyLimitExceededException from a ValidationException, read the trace ID...
 */
final class CaliseroTransportException extends RuntimeException implements TransportExceptionInterface
{
    public function __construct(
        private readonly ApiException $apiException,
    ) {
        parent::__construct(\sprintf('Unable to send the SMS through Calisero: %s', $apiException->getMessage()), $apiException->getStatusCode() ?? 0, $apiException);
    }

    public function getApiException(): ApiException
    {
        return $this->apiException;
    }

    /**
     * The HTTP status, the trace ID to quote to Calisero support, and the error body.
     */
    public function getDebug(): string
    {
        $debug = \sprintf("Exception: %s\nHTTP status: %s\nTrace ID: %s\n", $this->apiException::class, $this->apiException->getStatusCode() ?? 'none (no answer)', $this->apiException->getTraceId() ?? 'none');

        $details = $this->apiException->getErrorDetails();

        if ([] !== $details) {
            $debug .= 'Error body: '.json_encode($details, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
        }

        return $debug;
    }
}
