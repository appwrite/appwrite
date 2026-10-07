<?php

declare(strict_types=1);

namespace Tests\Unit\Task\Validator;

use Appwrite\Task\Validator\Interval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntervalTest extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed}>
     */
    public static function valid(): \Iterator
    {
        yield 'disabled' => [0];
        yield 'minute' => [60];
        yield 'hour' => [3600];
        yield 'day' => [86400];
        yield 'numeric string' => ['3600'];
    }

    /**
     * @return \Iterator<string, array{mixed}>
     */
    public static function invalid(): \Iterator
    {
        yield 'second' => [1];
        yield 'unlisted' => [1234];
        yield 'negative' => [-3600];
        yield 'above day' => [172800];
        yield 'float' => [3600.0];
        yield 'decimal string' => ['3600.5'];
        yield 'signed string' => ['-60'];
        yield 'text' => ['hourly'];
        yield 'null' => [null];
        yield 'boolean' => [true];
    }

    #[DataProvider('valid')]
    public function testAcceptsListedValues(mixed $value): void
    {
        $this->assertTrue((new Interval())->isValid($value));
    }

    #[DataProvider('invalid')]
    public function testRejectsOtherValues(mixed $value): void
    {
        $this->assertFalse((new Interval())->isValid($value));
    }
}
