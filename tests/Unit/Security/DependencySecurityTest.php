<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Mime\Address;

final class DependencySecurityTest extends TestCase
{
    public function test_header_parsing_preserves_whitespace_separators_and_cache_directives(): void
    {
        $this->assertSame(['foo', 'bar'], HeaderUtils::split('foo bar', ' '));
        $this->assertSame([['public'], ['max-age', '60']], HeaderUtils::split('public, max-age=60', ',='));
    }

    #[DataProvider('addressesWithLineBreaks')]
    public function test_mail_transport_boundary_rejects_addresses_with_raw_line_breaks(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Address($address);
    }

    public static function addressesWithLineBreaks(): array
    {
        return [
            'CRLF' => ['"x'."\r\n".'Bcc: audit@example.invalid"@example.invalid'],
            'LF' => ['"x'."\n".'Bcc: audit@example.invalid"@example.invalid'],
            'CR' => ['"x'."\r".'Bcc: audit@example.invalid"@example.invalid'],
        ];
    }

    public function test_normal_email_and_japanese_display_name_are_preserved(): void
    {
        $address = new Address('student@example.invalid', '検証用受講生');

        $this->assertSame('student@example.invalid', $address->getAddress());
        $this->assertSame('検証用受講生', $address->getName());
    }
}
