<?php

namespace Tests\Unit\Storage;

use Appwrite\Storage\Limit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LimitTest extends TestCase
{
    #[DataProvider('limits')]
    public function testMaximum(int $server, int $plan, int $bucket, int $maximum): void
    {
        $limit = new Limit($server, $plan);

        $this->assertSame($maximum, $limit->maximum($bucket));
    }

    public static function limits(): array
    {
        return [
            'unlimited server and bucket' => [0, 0, 0, 0],
            'bucket inherits server' => [30, 0, 0, 30],
            'bucket limits unlimited server' => [0, 0, 5, 5],
            'smaller bucket' => [30, 0, 5, 5],
            'server caps existing larger bucket' => [5, 0, 30, 5],
            'zero cannot bypass plan' => [0, 30, 0, 30],
            'smaller plan' => [30, 5, 0, 5],
            'smaller server than plan' => [5, 30, 0, 5],
            'bucket within plan' => [0, 30, 5, 5],
            'integer representation limit' => [0, 0, PHP_INT_MAX, PHP_INT_MAX],
        ];
    }

    #[DataProvider('sizes')]
    public function testAllows(int $server, int $plan, int $bucket, mixed $size, bool $allowed): void
    {
        $limit = new Limit($server, $plan);

        $this->assertSame($allowed, $limit->allows($size, $bucket));
    }

    public static function sizes(): array
    {
        return [
            'unlimited accepts beyond default' => [0, 0, 0, 30000001, true],
            'unlimited accepts representable integer' => [0, 0, 0, PHP_INT_MAX, true],
            'zero byte file' => [0, 0, 0, 0, true],
            'server boundary' => [30, 0, 0, 30, true],
            'server rejects larger file' => [30, 0, 0, 31, false],
            'bucket boundary with unlimited server' => [0, 0, 5, 5, true],
            'bucket rejects larger file' => [0, 0, 5, 6, false],
            'positive caps reject larger file' => [30, 0, 5, 6, false],
            'plan rejects larger file' => [0, 5, 0, 6, false],
            'negative size' => [0, 0, 0, -1, false],
            'float size' => [0, 0, 0, 1.5, false],
            'numeric string size' => [0, 0, 0, '1', false],
            'missing size' => [0, 0, 0, null, false],
            'infinite size' => [0, 0, 0, INF, false],
        ];
    }

    #[DataProvider('negativeLimits')]
    public function testRejectsNegativeLimits(int $server, int $plan, int $bucket): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Limit($server, $plan))->maximum($bucket);
    }

    public static function negativeLimits(): array
    {
        return [
            'server' => [-1, 0, 0],
            'plan' => [0, -1, 0],
            'bucket' => [0, 0, -1],
        ];
    }
}
