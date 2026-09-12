<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ActionTest extends TestCase
{
    public static function provideIntRanges(): array
    {
        return [
            'int32 bounds inclusive' => [-2147483648, 2147483647, 4],
            'small range' => [-1, 1, 4],
            'zero range' => [0, 0, 4],
            'one above int32 max' => [0, 2147483648, 8],
            'one below int32 min' => [-2147483649, 0, 8],
            'full 64-bit range' => [\PHP_INT_MIN, \PHP_INT_MAX, 8],
            'int32 min with 64-bit max' => [-2147483648, \PHP_INT_MAX, 8],
        ];
    }

    #[DataProvider('provideIntRanges')]
    public function testIntRangeColumnSize(int $min, int $max, int $expected): void
    {
        $this->assertSame($expected, Action::intRangeColumnSize($min, $max));
    }
}
