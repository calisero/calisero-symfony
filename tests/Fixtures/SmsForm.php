<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Fixtures;

use Calisero\SymfonySms\Validator\PhoneE164;
use Calisero\SymfonySms\Validator\SenderId;

/**
 * A form's data, validated with the bundle's constraints as attributes.
 */
final class SmsForm
{
    public function __construct(
        #[PhoneE164]
        public ?string $phone = null,
        #[SenderId]
        public ?string $sender = null,
    ) {
    }
}
