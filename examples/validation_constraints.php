<?php

declare(strict_types=1);

/*
 * Example: validate a phone number and a sender ID
 *
 * PhoneE164: +, the country code and the number, 7 to 15 digits in all.
 * SenderId: 3 to 11 letters, digits, spaces, hyphens and dots (approved by Calisero first).
 * Null and empty strings pass: add NotBlank to require a value. The messages are
 * translated into Romanian (validators domain).
 */

namespace App\Form\Model;

use Calisero\SymfonySms\Validator\PhoneE164;
use Calisero\SymfonySms\Validator\SenderId;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SmsCampaign
{
    #[Assert\NotBlank]
    #[PhoneE164]
    public ?string $phone = null;

    #[SenderId(lengthMessage: 'A sender ID has 3 to 11 characters.')]
    public ?string $sender = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 1600)]
    public ?string $text = null;
}

final class SmsCampaignValidation
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, string> the messages, by field
     */
    public function errors(SmsCampaign $campaign): array
    {
        $errors = [];

        foreach ($this->validator->validate($campaign) as $violation) {
            $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $errors;
    }

    /**
     * A value alone, outside of a class.
     */
    public function isValidPhone(string $phone): bool
    {
        return 0 === \count($this->validator->validate($phone, [new Assert\NotBlank(), new PhoneE164()]));
    }
}
