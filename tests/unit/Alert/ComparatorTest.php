<?php

declare(strict_types=1);

namespace Tests\Unit\Alert;

use Appwrite\Alert\Comparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComparatorTest extends TestCase
{
    /**
     * @return \Iterator<string, array{Comparator, float, bool}>
     */
    public static function comparisons(): \Iterator
    {
        yield 'greater than, above' => [Comparator::GreaterThan, 11, true];
        yield 'greater than, equal' => [Comparator::GreaterThan, 10, false];
        yield 'greater than or equal, equal' => [Comparator::GreaterThanEqual, 10, true];
        yield 'greater than or equal, below' => [Comparator::GreaterThanEqual, 9, false];
        yield 'less than, below' => [Comparator::LessThan, 9, true];
        yield 'less than, equal' => [Comparator::LessThan, 10, false];
        yield 'less than or equal, equal' => [Comparator::LessThanEqual, 10, true];
        yield 'less than or equal, above' => [Comparator::LessThanEqual, 11, false];
    }

    #[DataProvider('comparisons')]
    public function testMatchesAgainstThreshold(Comparator $comparator, float $value, bool $expected): void
    {
        $this->assertSame($expected, $comparator->matches($value, 10));
    }
}
