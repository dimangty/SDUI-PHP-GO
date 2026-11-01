<?php
declare(strict_types=1);

namespace Shop\DevTools\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shop\domain\Ids;

final class IdsTest extends TestCase
{
    #[DataProvider('canonicalUuids')]
    public function testCanonicalIdentityIsIndependentOfUuidCase(string $input, string $expected): void
    {
        self::assertSame($expected, Ids::canonical($input));
        self::assertSame($expected, Ids::canonical(Ids::canonical($input)));
    }

    public static function canonicalUuids(): iterable
    {
        yield 'lowercase' => ['abcdef01-2345-6789-abcd-ef0123456789', 'abcdef01-2345-6789-abcd-ef0123456789'];
        yield 'uppercase' => ['ABCDEF01-2345-6789-ABCD-EF0123456789', 'abcdef01-2345-6789-abcd-ef0123456789'];
        yield 'mixed case' => ['aBcDeF01-2345-6789-AbCd-eF0123456789', 'abcdef01-2345-6789-abcd-ef0123456789'];
        // Ids validates UUID syntax, not a particular RFC version or variant.
        yield 'nil UUID' => ['00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000000'];
        yield 'all hex digits' => ['FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF', 'ffffffff-ffff-ffff-ffff-ffffffffffff'];
    }

    #[DataProvider('invalidIdentities')]
    public function testCanonicalRejectsMalformedAndOpaqueIdentities(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ids::canonical($input);
    }

    public static function invalidIdentities(): iterable
    {
        yield 'empty' => [''];
        yield 'opaque token' => ['Opaque_Token-Aa09'];
        yield 'provider ID' => ['demo-abcdef01-2345-6789-abcd-ef0123456789'];
        yield 'signature' => ['abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789'];
        yield 'compact UUID' => ['abcdef0123456789abcdef0123456789'];
        yield 'short UUID' => ['abcdef01-2345-6789-abcd-ef012345678'];
        yield 'long UUID' => ['abcdef01-2345-6789-abcd-ef01234567890'];
        yield 'nonhex UUID' => ['gbcdef01-2345-6789-abcd-ef0123456789'];
        yield 'braced UUID' => ['{abcdef01-2345-6789-abcd-ef0123456789}'];
        yield 'leading space' => [' abcdef01-2345-6789-abcd-ef0123456789'];
        yield 'trailing space' => ['abcdef01-2345-6789-abcd-ef0123456789 '];
        yield 'trailing newline' => ["abcdef01-2345-6789-abcd-ef0123456789\n"];
        yield 'embedded NUL' => ["abcdef01-2345-6789-abcd-ef0123456789\0"];
        yield 'URL encoded hyphens' => ['abcdef01%2D2345%2D6789%2Dabcd%2Def0123456789'];
    }
}
