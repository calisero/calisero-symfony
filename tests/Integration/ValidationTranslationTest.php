<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Integration;

use Calisero\SymfonySms\Tests\Fixtures\SmsForm;
use Calisero\SymfonySms\Tests\IntegrationTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Tests the constraints through the application's validator, in English and Romanian.
 */
final class ValidationTranslationTest extends IntegrationTestCase
{
    public function testTheMessagesAreInEnglishByDefault(): void
    {
        $this->assertSame([
            'phone' => 'This value is not a valid E.164 phone number.',
            'sender' => 'This value should be between 3 and 11 characters long.',
        ], $this->violations('en'));
    }

    public function testTheMessagesAreTranslatedToRomanian(): void
    {
        $this->assertSame([
            'phone' => 'Această valoare nu este un număr de telefon valid în format E.164.',
            'sender' => 'Această valoare trebuie să aibă între 3 și 11 caractere.',
        ], $this->violations('ro'));
    }

    /**
     * @return array<string, string>
     */
    private function violations(string $locale): array
    {
        $translator = $this->service('test.translator', LocaleAwareInterface::class);
        $translator->setLocale($locale);

        $messages = [];

        foreach ($this->service('test.validator', ValidatorInterface::class)->validate(new SmsForm('0712345678', 'X')) as $violation) {
            $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
