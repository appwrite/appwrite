<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Validator;

use Appwrite\Event\Validator\Reason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReasonTest extends TestCase
{
    private const int LENGTH = 64;

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function validReasons(): \Iterator
    {
        yield 'empty' => [''];
        yield 'plain' => ['Moved to SSR endpoint /api/checkout in storefront site'];
        yield 'symbols' => ['50% off, "quoted" & <tagged> — 100 % sure; a\\b'];
        yield 'czech' => ['Tým podpory pro spuštění obchodu'];
        yield 'hebrew' => ['העברת בעלות לצוות התמיכה'];
        yield 'japanese' => ['ドメインを移譲'];
        yield 'emoji with joiners' => ['Handed to 👨‍👩‍👧 team'];
        yield 'directional marks' => ["\u{200F}שלום\u{200E} ok"];
        yield 'at the limit' => [\str_repeat('a', self::LENGTH)];
        yield 'multibyte at the limit' => [\str_repeat('ž', self::LENGTH)];
    }

    /**
     * @return \Iterator<string, array{mixed}>
     */
    public static function invalidReasons(): \Iterator
    {
        yield 'not a string' => [123];
        yield 'over the limit' => [\str_repeat('a', self::LENGTH + 1)];
        yield 'invalid utf-8' => ["\xFF\xFE"];
        yield 'line feed' => ["first\nsecond"];
        yield 'carriage return' => ["first\rsecond"];
        yield 'tab' => ["first\tsecond"];
        yield 'nul' => ["first\0second"];
        yield 'escape' => ["\e[31mred"];
        yield 'delete' => ["first\x7Fsecond"];
        yield 'c1 control' => ["first\u{85}second"];
        yield 'line separator' => ["first\u{2028}second"];
        yield 'paragraph separator' => ["first\u{2029}second"];
        yield 'right-to-left override' => ["safe\u{202E}txt.exe"];
        yield 'left-to-right embedding' => ["\u{202A}text"];
        yield 'right-to-left isolate' => ["\u{2067}text\u{2069}"];
    }

    #[DataProvider('validReasons')]
    public function testValid(string $reason): void
    {
        $this->assertTrue((new Reason(self::LENGTH))->isValid($reason));
    }

    #[DataProvider('invalidReasons')]
    public function testInvalid(mixed $reason): void
    {
        $this->assertFalse((new Reason(self::LENGTH))->isValid($reason));
    }
}
