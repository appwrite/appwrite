<?php

namespace Utopia\Query\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\StringLiteral;

final class StringLiteralTest extends TestCase
{
    /**
     * @return array<string, array{StringLiteral, string, string}>
     */
    public static function quotedProvider(): array
    {
        return [
            'backslash plain' => [StringLiteral::Backslash, 'abc', "'abc'"],
            'backslash quote' => [StringLiteral::Backslash, "it's", "'it''s'"],
            'backslash before quote' => [StringLiteral::Backslash, "\\' OR 1=1 -- ", "'\\\\'' OR 1=1 -- '"],
            'backslash ascii path' => [StringLiteral::Backslash, 'C:\\path\\to', "'C:\\\\path\\\\to'"],
            'backslash multibyte without backslash' => [StringLiteral::Backslash, "Zo\u{EB}'s", "'Zo\u{EB}''s'"],
            'backslash after ascii next to multibyte' => [StringLiteral::Backslash, "\u{EB}a\\", "'\u{EB}a\\\\'"],
            'backslash keeps nul' => [StringLiteral::Backslash, "a\0b", "'a\0b'"],
            'extended plain' => [StringLiteral::Extended, 'abc', "'abc'"],
            'extended quote' => [StringLiteral::Extended, "it's", "'it''s'"],
            'extended backslash' => [StringLiteral::Extended, 'a\\b', "E'a\\\\b'"],
            'extended backslash before quote' => [StringLiteral::Extended, "\\' OR 1=1 -- ", "E'\\\\'' OR 1=1 -- '"],
            'extended multibyte without backslash' => [StringLiteral::Extended, "Zo\u{EB}'s", "'Zo\u{EB}''s'"],
            'standard quote' => [StringLiteral::Standard, "it's", "'it''s'"],
            'standard backslash' => [StringLiteral::Standard, "a\\b'c", "'a\\b''c'"],
            'standard multibyte before backslash' => [StringLiteral::Standard, "Zo\u{EB}\\x", "'Zo\u{EB}\\x'"],
            'backslash utf8 quote' => [StringLiteral::BackslashUtf8, "it's", "'it''s'"],
            'backslash utf8 backslash before quote' => [StringLiteral::BackslashUtf8, "x\\'", "'x\\\\'''"],
            'backslash utf8 multibyte before backslash' => [StringLiteral::BackslashUtf8, "Zo\u{EB}\\x", "'Zo\u{EB}\\\\x'"],
            'backslash utf8 keeps nul' => [StringLiteral::BackslashUtf8, "a\0b", "'a\0b'"],
        ];
    }

    #[DataProvider('quotedProvider')]
    public function testQuote(StringLiteral $literal, string $value, string $expected): void
    {
        $this->assertSame($expected, $literal->quote($value));
    }

    /**
     * @return array<string, array{StringLiteral, string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'backslash gbk lead byte' => [StringLiteral::Backslash, "\xbf\\' OR 1=1"],
            'backslash utf-8 continuation byte' => [StringLiteral::Backslash, "Zo\u{EB}\\x"],
            'extended gbk lead byte' => [StringLiteral::Extended, "\xbf\\' OR 1=1"],
            'extended utf-8 continuation byte' => [StringLiteral::Extended, "Zo\u{EB}\\x"],
            'extended nul' => [StringLiteral::Extended, "a\0b"],
            'standard nul' => [StringLiteral::Standard, "a\0b"],
        ];
    }

    #[DataProvider('refusedProvider')]
    public function testQuoteRefuses(StringLiteral $literal, string $value): void
    {
        $this->expectException(ValidationException::class);

        $literal->quote($value);
    }
}
