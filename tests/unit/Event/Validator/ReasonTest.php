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
     * @return array<string, array{string}>
     */
    public static function validReasons(): array
    {
        return [
            'empty' => [''],
            'plain' => ['Moved to SSR endpoint /api/checkout in storefront site'],
            'symbols' => ['50% off, "quoted" & <tagged> — 100 % sure; a\\b'],
            'czech' => ['Tým podpory pro spuštění obchodu'],
            'hebrew' => ['העברת בעלות לצוות התמיכה'],
            'japanese' => ['ドメインを移譲'],
            'emoji with joiners' => ['Handed to 👨‍👩‍👧 team'],
            'directional marks' => ["\u{200F}שלום\u{200E} ok"],
            'at the limit' => [\str_repeat('a', self::LENGTH)],
            'multibyte at the limit' => [\str_repeat('ž', self::LENGTH)],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidReasons(): array
    {
        return [
            'not a string' => [123],
            'over the limit' => [\str_repeat('a', self::LENGTH + 1)],
            'invalid utf-8' => ["\xFF\xFE"],
            'line feed' => ["first\nsecond"],
            'carriage return' => ["first\rsecond"],
            'tab' => ["first\tsecond"],
            'nul' => ["first\0second"],
            'escape' => ["\e[31mred"],
            'delete' => ["first\x7Fsecond"],
            'c1 control' => ["first\u{85}second"],
            'line separator' => ["first\u{2028}second"],
            'paragraph separator' => ["first\u{2029}second"],
            'right-to-left override' => ["safe\u{202E}txt.exe"],
            'left-to-right embedding' => ["\u{202A}text"],
            'right-to-left isolate' => ["\u{2067}text\u{2069}"],
        ];
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
