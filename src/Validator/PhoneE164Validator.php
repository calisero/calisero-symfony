<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PhoneE164Validator extends ConstraintValidator
{
    /**
     * +, a country code that does not start with 0, then the number: 7 to 15 digits in all.
     */
    public const PATTERN = '/^\+[1-9]\d{6,14}$/D';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PhoneE164) {
            throw new UnexpectedTypeException($constraint, PhoneE164::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value) && !$value instanceof \Stringable) {
            throw new UnexpectedValueException($value, 'string');
        }

        $value = (string) $value;

        if (1 !== preg_match(self::PATTERN, $value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setCode(PhoneE164::INVALID_FORMAT_ERROR)
                ->addViolation();
        }
    }
}
