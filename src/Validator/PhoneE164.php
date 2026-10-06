<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * A phone number in E.164 format, as the Calisero API takes it: +, the country code and
 * the number, 7 to 15 digits in all, e.g. +40712345678.
 *
 * Null and empty strings are valid: add NotBlank to require a value.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class PhoneE164 extends Constraint
{
    public const INVALID_FORMAT_ERROR = '4b3c6f8e-2a1d-4e5f-9c7b-8d6e5f4a3b21';

    protected const ERROR_NAMES = [
        self::INVALID_FORMAT_ERROR => 'INVALID_FORMAT_ERROR',
    ];

    public string $message = 'This value is not a valid E.164 phone number.';

    /**
     * @param string[]|null $groups
     */
    #[HasNamedArguments]
    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
