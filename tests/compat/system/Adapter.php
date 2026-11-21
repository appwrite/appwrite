<?php

namespace Tests\Compat\System;

require_once __DIR__ . '/Fixture.php';
require_once __DIR__ . '/overrides.php';

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\System\System;

/**
 * Maps tests/compat/system/spec.json operations onto utopia-php/system. Glue only: no logic.
 *
 * `system.fixture` runs a method against a described machine (Fixture), so
 * both runtimes parse the same /proc, /sys and sysctl content;
 * `system.machine` runs it against the real machine and reports only the
 * shape of the result, since the PHP driver runs in a Linux container and
 * the Rust driver on the host.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        $machine = fn (): array => json_decode((string) file_get_contents(__DIR__ . '/benchmarks/machine.json'), true);

        return [
            'env' => ['Read a set environment variable', function (Session $s) {
                putenv('_APP_BENCH_ENV=production');
                putenv('_APP_BENCH_UNSET');
                $name = '_APP_BENCH_ENV';

                return fn () => System::getEnv($name);
            }],
            'env_default' => ['Read an unset environment variable with a default', function (Session $s) {
                putenv('_APP_BENCH_ENV=production');
                putenv('_APP_BENCH_UNSET');
                [$name, $default] = ['_APP_BENCH_UNSET', 'development'];

                return fn () => System::getEnv($name, $default);
            }],
            // Each call reads a fresh machine, as each Rust call does.
            'memory' => ['Read the memory capacity from a 51-line /proc/meminfo and a cgroup v2 limit', fn (Session $s) => self::on($machine(), fn () => System::getMemory())],
            'cpu' => ['Read the CPUs available from a cgroup v2 quota, a cpuset and an 8-core /proc/cpuinfo', fn (Session $s) => self::on($machine(), fn () => System::getCPU())],
            'cpu_usage' => ['Measure CPU usage from two readings of an 8-core /proc/stat', fn (Session $s) => self::on($machine(), fn () => System::getCPUUsage(1))],
        ];
    }

    /**
     * A benchmark running `$call` on a fresh Fixture of `$machine`.
     *
     * @param array<string, mixed> $machine
     * @param callable(): mixed $call
     * @return callable(): mixed
     */
    private static function on(array $machine, callable $call): callable
    {
        return function () use ($machine, $call) {
            Fixture::$active = new Fixture($machine);
            try {
                return $call();
            } finally {
                Fixture::$active = null;
            }
        };
    }

    public function operations(): array
    {
        return [
            'system.env' => function (array $a, Session $s) {
                putenv(isset($a['value']) ? "{$a['name']}={$a['value']}" : $a['name']);

                return \array_key_exists('default', $a) ? System::getEnv($a['name'], $a['default']) : System::getEnv($a['name']);
            },
            'system.fixture' => function (array $a, Session $s) {
                $fixture = new Fixture($a['machine']);
                Fixture::$active = $fixture;
                try {
                    $value = self::call($a);
                } finally {
                    Fixture::$active = null;
                }

                return ['value' => $value, 'sleeps' => $fixture->sleeps];
            },
            'system.machine' => fn (array $a, Session $s) => self::shape(self::call($a)),
        ];
    }

    private static function call(array $a): mixed
    {
        return match ($a['call']) {
            'getOS' => System::getOS(),
            'getArch' => System::getArch(),
            'getArchEnum' => System::getArchEnum(),
            'getHostname' => System::getHostname(),
            'getCPUCores' => System::getCPUCores(),
            'getCPU' => System::getCPU(),
            'getCPUUsage' => System::getCPUUsage($a['duration']),
            'getMemoryTotal' => System::getMemoryTotal(),
            'getMemory' => System::getMemory(),
            'getMemoryFree' => System::getMemoryFree(),
            'getMemoryAvailable' => System::getMemoryAvailable(),
            'getDiskTotal' => System::getDiskTotal($a['directory']),
            'getDiskFree' => System::getDiskFree($a['directory']),
            'getIOUsage' => System::getIOUsage($a['duration']),
            'getNetworkUsage' => System::getNetworkUsage($a['duration']),
            'isX86' => System::isX86(),
            'isPPC' => System::isPPC(),
            'isArm64' => System::isArm64(),
            'isArmV7' => System::isArmV7(),
            'isArmV8' => System::isArmV8(),
            'isArch' => System::isArch($a['arch']),
            default => throw new Fault("unknown System method {$a['call']}"),
        };
    }

    /**
     * The type of a machine-dependent result, with the facts SystemTest asserts.
     *
     * @return array<string, mixed>
     */
    private static function shape(mixed $value): array
    {
        return match (true) {
            \is_string($value) => ['type' => 'string', 'empty' => $value === ''],
            \is_int($value), \is_float($value) => ['type' => get_debug_type($value), 'sign' => $value <=> 0],
            \is_array($value) => ['type' => 'array', 'total' => isset($value['total'])],
            default => ['type' => get_debug_type($value)],
        };
    }
}
