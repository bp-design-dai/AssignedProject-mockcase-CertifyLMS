<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailValidatorTest extends TestCase
{
    #[DataProvider('addressesWithLineBreaks')]
    public function test_email_rule_rejects_raw_line_breaks_with_the_existing_email_error(string $email): void
    {
        $validator = Validator::make(['email' => $email], ['email' => 'required|email']);

        $this->assertFalse($validator->passes());
        $this->assertSame(['Email' => []], $validator->failed()['email']);
        $this->assertSame(trans('validation.email', ['attribute' => 'email']), $validator->errors()->first('email'));
    }

    public static function addressesWithLineBreaks(): array
    {
        $cases = [];
        foreach (['CR' => "\r", 'LF' => "\n", 'CRLF' => "\r\n"] as $name => $break) {
            $cases[$name.' quoted'] = ['"local'.$break.'part"@example.test'];
            $cases[$name.' leading'] = [$break.'student@example.test'];
            $cases[$name.' trailing'] = ['student@example.test'.$break];
        }

        return $cases;
    }

    #[DataProvider('normalAddresses')]
    public function test_normal_addresses_keep_the_framework_result(string $email): void
    {
        $this->assertTrue(Validator::make(['email' => $email], ['email' => 'required|email'])->passes());
    }

    public static function normalAddresses(): array
    {
        return [
            ['student@example.test'],
            ['Student+tag@sub.example.test'],
            ['"two words"@example.test'],
        ];
    }

    public function test_other_rules_optional_values_and_non_email_text_keep_their_behavior(): void
    {
        $this->assertTrue(Validator::make(['email' => null, 'body' => "first\r\nsecond"], ['email' => 'nullable|email', 'body' => 'string'])->passes());
        foreach ([[], 0, 'not-an-address'] as $value) {
            $this->assertFalse(Validator::make(['email' => $value], ['email' => 'email'])->passes());
        }
        $this->assertTrue(Validator::make(['email' => 'student@example.test'], ['email' => 'email:filter'])->passes());
        $this->assertFalse(Validator::make(['email' => 'student@example.test'], ['email' => 'email|max:5'])->passes());
    }
}
