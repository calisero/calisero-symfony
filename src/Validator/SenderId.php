<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * An alphanumeric sender ID: 3 to 11 characters, letters, digits, spaces, hyphens and
 * dots. It must also be approved by Calisero before it is used.
 *
 * Null and empty strings are valid: add NotBlank to require a value.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class SenderId extends Constraint
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 11;

    public const INVALID_LENGTH_ERROR = '7e2d1c4b-9a8f-4b6e-8d5c-3f2a1b0c9d8e';

    public const INVALID_CHARACTERS_ERROR = '1a9b8c7d-6e5f-4a3b-9c2d-1e0f9a8b7c6d';

    protected const ERROR_NAMES = [
        self::INVALID_LENGTH_ERROR => 'INVALID_LENGTH_ERROR',
        self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR',
    ];

    public string $lengthMessage = 'This value should be between {{ min }} and {{ max }} characters long.';

    public string $charactersMessage = 'This value may only contain letters, digits, spaces, hyphens and dots.';

    /**
     * @param string[]|null $groups
     */
    #[HasNamedArguments]
    public function __construct(?string $lengthMessage = null, ?string $charactersMessage = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);

        $this->lengthMessage = $lengthMessage ?? $this->lengthMessage;
        $this->charactersMessage = $charactersMessage ?? $this->charactersMessage;
    }
}
