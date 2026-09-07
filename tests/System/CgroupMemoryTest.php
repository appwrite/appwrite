<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Utopia\System\System;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CgroupMemoryTest extends TestCase
{
    /** @var array<string, string|false> */
    public static array $files = [];

    protected function setUp(): void
    {
        require __DIR__ . '/../fixtures/cgroup-memory.php';
    }

    /**
     * @param array<string, string|false> $files
     */
    #[DataProvider('memoryLimits')]
    public function testGetMemory(array $files, int $expected): void
    {
        self::$files = $files + ['/proc/meminfo' => "MemTotal:       8388608 kB\n"];

        $this->assertSame($expected, System::getMemory());
        $this->assertSame(8192, System::getMemoryTotal());
    }

    /**
     * @return \Iterator<string, array{array<string, (string | false)>, int}>
     */
    public static function memoryLimits(): \Iterator
    {
        $v2 = '/sys/fs/cgroup/memory.max';
        $v1 = '/sys/fs/cgroup/memory/memory.limit_in_bytes';
        yield 'v2 limit' => [[$v2 => "536870912\n"], 512];
        yield 'v1 limit' => [[$v1 => "268435456\n"], 256];
        yield 'v2 takes precedence' => [[$v2 => '536870912', $v1 => '268435456'], 512];
        yield 'missing cgroups' => [[], 8192];
        yield 'v2 unlimited' => [[$v2 => "max\n"], 8192];
        yield 'v2 unlimited takes precedence' => [[$v2 => 'max', $v1 => '268435456'], 8192];
        yield 'v1 unlimited sentinel' => [[$v1 => '9223372036854771712'], 8192];
        yield 'v1 negative unlimited' => [[$v1 => '-1'], 8192];
        yield 'limit exceeds host' => [[$v2 => '17179869184'], 8192];
        yield 'round down to MiB' => [[$v2 => '1572864'], 1];
        yield 'sub MiB limit' => [[$v2 => '524288'], 0];
        yield 'zero limit' => [[$v2 => '0'], 0];
        yield 'empty limit' => [[$v2 => ''], 8192];
        yield 'invalid limit' => [[$v2 => 'invalid'], 8192];
        yield 'negative limit' => [[$v2 => '-2'], 8192];
        yield 'fractional bytes' => [[$v2 => '536870912.5'], 8192];
        yield 'overflow' => [[$v2 => '18446744073709551615'], 8192];
        yield 'read failure' => [[$v2 => false], 8192];
        yield 'v1 fallback on read failure' => [[$v2 => false, $v1 => '268435456'], 256];
    }
}
