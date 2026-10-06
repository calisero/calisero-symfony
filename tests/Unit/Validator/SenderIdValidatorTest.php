<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Validator;

use Calisero\SymfonySms\Tests\Fixtures\SmsForm;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use Calisero\SymfonySms\Validator\SenderId;
use Calisero\SymfonySms\Validator\SenderIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SenderIdValidatorTest extends TestCase
{
    #[DataProvider('validSenders')]
    public function testItAcceptsASenderId(?string $value): void
    {
        $this->assertCount(0, self::validator()->validate($value, new SenderId()));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function validSenders(): iterable
    {
        yield 'letters' => ['CALISERO'];
        yield 'a space' => ['My Shop'];
        yield 'a dash, a dot and digits' => ['Shop-1.ro'];
        yield 'the shortest, 3 characters' => ['ABC'];
        yield 'the longest, 11 characters' => ['ABCDEFGHIJK'];
        yield 'null: NotBlank requires a value' => [null];
        yield 'an empty string: NotBlank requires a value' => [''];
    }

    #[DataProvider('sendersOfTheWrongLength')]
    public function testItRefusesASenderIdOfTheWrongLength(string $value): void
    {
        $violations = self::validator()->validate($value, new SenderId());

        $this->assertCount(1, $violations);
        $this->assertSame('This value should be between 3 and 11 characters long.', $violations->get(0)->getMessage());
        $this->assertSame(SenderId::INVALID_LENGTH_ERROR, $violations->get(0)->getCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sendersOfTheWrongLength(): iterable
    {
        yield '2 characters' => ['AB'];
        yield '12 characters' => ['ABCDEFGHIJKL'];
        // Refused for its length before its @
        yield '12 characters, one of them not allowed' => ['Test@Company'];
    }

    #[DataProvider('sendersWithForbiddenCharacters')]
    public function testItRefusesASenderIdWithACharacterThatIsNotAllowed(string $value): void
    {
        $violations = self::validator()->validate($value, new SenderId());

        $this->assertCount(1, $violations);
        $this->assertSame('This value may only contain letters, digits, spaces, hyphens and dots.', $violations->get(0)->getMessage());
        $this->assertSame(SenderId::INVALID_CHARACTERS_ERROR, $violations->get(0)->getCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sendersWithForbiddenCharacters(): iterable
    {
        yield 'an @' => ['Shop@Home'];
        yield 'an underscore' => ['Shop_1'];
        yield 'a diacritic' => ['Șoseaua'];
        yield 'a tab' => ["Shop\tOne"];
        yield 'a line break' => ["Shop\nOne"];
        // `$` alone would match before it
        yield 'a line break at the end' => ["CALISERO\n"];
        yield 'a phone number' => [TestPhones::DEFAULT];
    }

    public function testItRefusesAValueThatIsNotAString(): void
    {
        $violations = self::validator()->validate(['CALISERO'], new SenderId());

        $this->assertCount(1, $violations);
        $this->assertSame('This value should be of type string.', $violations->get(0)->getMessage());
    }

    public function testItTakesCustomMessages(): void
    {
        $constraint = new SenderId(lengthMessage: 'Too long for a sender.', charactersMessage: 'Letters and digits only.');

        $this->assertSame('Too long for a sender.', self::validator()->validate('ABCDEFGHIJKL', $constraint)->get(0)->getMessage());
        $this->assertSame('Letters and digits only.', self::validator()->validate('Shop@Home', $constraint)->get(0)->getMessage());
    }

    public function testItValidatesOnlyItsOwnConstraint(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        (new SenderIdValidator())->validate('CALISERO', new NotBlank());
    }

    public function testItNamesItsErrorCodes(): void
    {
        $this->assertSame('INVALID_LENGTH_ERROR', SenderId::getErrorName(SenderId::INVALID_LENGTH_ERROR));
        $this->assertSame('INVALID_CHARACTERS_ERROR', SenderId::getErrorName(SenderId::INVALID_CHARACTERS_ERROR));
    }

    public function testItWorksAsAnAttribute(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new SmsForm(sender: 'CALISERO')));

        $violations = $validator->validate(new SmsForm(sender: 'X'));
        $this->assertCount(1, $violations);
        $this->assertSame('sender', $violations->get(0)->getPropertyPath());
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidator();
    }
}
