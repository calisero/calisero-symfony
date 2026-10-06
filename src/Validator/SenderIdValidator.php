<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class SenderIdValidator extends ConstraintValidator
{
    /**
     * Letters, digits, spaces, hyphens and dots, ASCII only.
     */
    public const PATTERN = '/^[A-Za-z0-9 .\-]+$/D';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SenderId) {
            throw new UnexpectedTypeException($constraint, SenderId::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value) && !$value instanceof \Stringable) {
            throw new UnexpectedValueException($value, 'string');
        }

        $value = (string) $value;
        $length = mb_strlen($value);

        if ($length < SenderId::MIN_LENGTH || $length > SenderId::MAX_LENGTH) {
            $this->context->buildViolation($constraint->lengthMessage)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setParameter('{{ min }}', (string) SenderId::MIN_LENGTH)
                ->setParameter('{{ max }}', (string) SenderId::MAX_LENGTH)
                ->setCode(SenderId::INVALID_LENGTH_ERROR)
                ->addViolation();

            return;
        }

        if (1 !== preg_match(self::PATTERN, $value)) {
            $this->context->buildViolation($constraint->charactersMessage)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setCode(SenderId::INVALID_CHARACTERS_ERROR)
                ->addViolation();
        }
    }
}
