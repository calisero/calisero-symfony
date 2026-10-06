<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Command;

use Calisero\Sms\Dto\Message;
use Calisero\Sms\Dto\ResponseMeta;
use Calisero\Sms\Dto\ShortenedLink;
use Calisero\Sms\Dto\Verification;
use Calisero\Sms\Exceptions\ApiException;
use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\Sms\Exceptions\ForbiddenException;
use Calisero\Sms\Exceptions\NotFoundException;
use Calisero\Sms\Exceptions\RateLimitedException;
use Calisero\Sms\Exceptions\ServerException;
use Calisero\Sms\Exceptions\TransportException;
use Calisero\Sms\Exceptions\UnauthorizedException;
use Calisero\Sms\Exceptions\ValidationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the outcome of a Calisero call the same way in every command.
 *
 * @internal
 */
trait RendersApiOutput
{
    /**
     * Print the failure and return the command's exit code.
     */
    private function renderFailure(SymfonyStyle $io, \Throwable $e, string $notFound = 'Resource not found'): int
    {
        if ($e instanceof DailyLimitExceededException) {
            // Before RateLimitedException, which it extends
            $io->error('Daily sending limit reached: '.$e->getMessage());
            $io->writeln('Daily limit: '.($e->getDailyLimit() ?? 'unknown').', resets at: '.($e->getResetsAt() ?? 'midnight, Romania time'));
        } elseif ($e instanceof ValidationException) {
            // A 422 covers an invalid phone, an invalid code, too many attempts...
            $io->error('API validation error: '.$e->getMessage());
            $this->renderValidationErrors($io, $e->getValidationErrors());
        } elseif ($e instanceof RateLimitedException) {
            $io->error('Rate limited: '.$e->getMessage());
            $io->writeln('Retry after: '.($e->getRetryAfter() ?? 'unknown').'s');
        } elseif ($e instanceof UnauthorizedException || $e instanceof ForbiddenException) {
            $io->error('Auth/permission error: '.$e->getMessage());
        } elseif ($e instanceof NotFoundException) {
            $io->error($notFound.': '.$e->getMessage());
        } elseif ($e instanceof ServerException) {
            $io->error('Server error: '.$e->getMessage());
        } elseif ($e instanceof TransportException) {
            $io->error('No answer from the API: '.$e->getMessage());
        } elseif ($e instanceof ApiException) {
            $io->error('API error: '.$e->getMessage().' (status: '.($e->getStatusCode() ?? 'unknown').')');
        } else {
            $io->error('Unexpected failure: '.$e->getMessage());
        }

        if ($e instanceof ApiException && null !== $e->getTraceId()) {
            $io->writeln('Trace ID: '.$e->getTraceId().' (quote it to Calisero support)');
        }

        return Command::FAILURE;
    }

    /**
     * @return list<array{string, string}>
     */
    private function messageRows(Message $message): array
    {
        return [
            ['ID', $message->getId()],
            ['Recipient', $message->getRecipient()],
            ['Sender', $message->getSender() ?? '—'],
            ['Body', $message->getBody()],
            ['Parts', (string) $message->getParts()],
            ['Status', $message->getStatus()],
            ['Created At', $message->getCreatedAt()],
            ['Scheduled At', $message->getScheduledAt() ?? '—'],
            ['Sent At', $message->getSentAt() ?? '—'],
            ['Delivered At', $message->getDeliveredAt() ?? '—'],
            ['Callback URL', $message->getCallbackUrl() ?? '—'],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    private function verificationRows(Verification $verification): array
    {
        return [
            ['ID', $verification->getId()],
            ['Phone', $verification->getPhone()],
            ['Status', $verification->getStatus()],
            ['Brand', $verification->getBrand() ?? '—'],
            ['Template', $verification->getTemplate() ?? '—'],
            ['Created At', $verification->getCreatedAt()],
            ['Expires At', $verification->getExpiresAt()],
            ['Verified At', $verification->getVerifiedAt() ?? '—'],
            ['Attempts', (string) $verification->getAttempts()],
            ['Expired', $verification->isExpired() ? 'Yes' : 'No'],
        ];
    }

    /**
     * Rows for what the answer's headers said about the account's daily limit and the
     * request; a value the answer did not carry is left out.
     *
     * @return list<array{string, string}>
     */
    private function responseMetaRows(ResponseMeta $meta): array
    {
        $rows = [];

        if (null !== $meta->getDailyLimit()) {
            $rows[] = ['Daily Remaining', $meta->getDailyRemaining().' of '.$meta->getDailyLimit()];
        }

        if (null !== $meta->getTraceId()) {
            $rows[] = ['Trace ID', $meta->getTraceId()];
        }

        return $rows;
    }

    /**
     * A table of the links Calisero shortened in a message, with their clicks.
     *
     * @param ShortenedLink[] $links
     */
    private function renderShortenedUrls(SymfonyStyle $io, array $links): void
    {
        if ([] === $links) {
            return;
        }

        $io->table(
            ['Original Link', 'Shortened Link', 'Clicks', 'Last Click'],
            array_map(static fn (ShortenedLink $link): array => [
                $link->getOriginalLink(),
                $link->getShortenedLink(),
                (string) $link->getClickCount(),
                $link->getLastClick() ?? '—',
            ], array_values($links)),
        );
    }

    /**
     * @param array<array-key, mixed> $errors
     */
    private function renderValidationErrors(SymfonyStyle $io, array $errors): void
    {
        if ([] === $errors) {
            return;
        }

        $rows = [];

        foreach ($errors as $field => $messages) {
            $messages = \is_array($messages) ? $messages : [$messages];
            $rows[] = [(string) $field, implode('; ', array_map(static fn (mixed $message): string => \is_scalar($message) ? (string) $message : get_debug_type($message), $messages))];
        }

        $io->table(['Field', 'Errors'], $rows);
    }
}
