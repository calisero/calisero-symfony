<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Validator;

use Calisero\SymfonySms\Tests\Fixtures\SmsForm;
use Calisero\SymfonySms\Tests\Support\TestPhones;
use Calisero\SymfonySms\Validator\PhoneE164;
use Calisero\SymfonySms\Validator\PhoneE164Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PhoneE164ValidatorTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function testItAcceptsAnE164Number(mixed $value): void
    {
        $this->assertCount(0, self::validator()->validate($value, new PhoneE164()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function validNumbers(): iterable
    {
        yield 'a typical number' => [TestPhones::DEFAULT];
        yield 'the shortest, 7 digits' => [TestPhones::SHORTEST_VALID];
        yield 'the longest, 15 digits' => [TestPhones::LONGEST_VALID];
        yield 'a Stringable' => [new class implements \Stringable {
            public function __toString(): string
            {
                return TestPhones::DEFAULT;
            }
        }];
        yield 'null: NotBlank requires a value' => [null];
        yield 'an empty string: NotBlank requires a value' => [''];
    }

    #[DataProvider('invalidNumbers')]
    public function testItRefusesANumberThatIsNotE164(string $value): void
    {
        $violations = self::validator()->validate($value, new PhoneE164());

        $this->assertCount(1, $violations);
        $this->assertSame('This value is not a valid E.164 phone number.', $violations->get(0)->getMessage());
        $this->assertSame(PhoneE164::INVALID_FORMAT_ERROR, $violations->get(0)->getCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'too short, 6 digits' => [TestPhones::TOO_SHORT];
        yield 'too long, 16 digits' => [TestPhones::TOO_LONG];
        yield 'no plus sign' => [ltrim(TestPhones::DEFAULT, '+')];
        yield 'a country code starting with 0' => ['+0995550100'];
        yield 'spaces' => ['+999 555 0100'];
        yield 'a dash' => ['+999-555-0100'];
        yield 'a letter' => ['+99955501OO'];
        yield 'two plus signs' => ['++9995550100'];
        // `$` alone would match before it
        yield 'a line break after it' => [TestPhones::DEFAULT."\n"];
    }

    public function testItRefusesAValueThatIsNotAString(): void
    {
        $violations = self::validator()->validate(9995550100, new PhoneE164());

        $this->assertCount(1, $violations);
        $this->assertSame('This value should be of type string.', $violations->get(0)->getMessage());
    }

    public function testItTakesACustomMessage(): void
    {
        $violations = self::validator()->validate('0712', new PhoneE164(message: 'Enter the number as +40712345678.'));

        $this->assertSame('Enter the number as +40712345678.', $violations->get(0)->getMessage());
    }

    public function testItValidatesOnlyItsOwnConstraint(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        (new PhoneE164Validator())->validate(TestPhones::DEFAULT, new NotBlank());
    }

    public function testItNamesItsErrorCode(): void
    {
        $this->assertSame('INVALID_FORMAT_ERROR', PhoneE164::getErrorName(PhoneE164::INVALID_FORMAT_ERROR));
    }

    public function testItWorksAsAnAttribute(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new SmsForm(TestPhones::DEFAULT)));

        $violations = $validator->validate(new SmsForm('0712345678'));
        $this->assertCount(1, $violations);
        $this->assertSame('phone', $violations->get(0)->getPropertyPath());
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidator();
    }
}
