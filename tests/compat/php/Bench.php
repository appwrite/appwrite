<?php

namespace Tests\Compat;

/**
 * Times a benchmark closure the way the Rust driver does
 * (crates/tools/compat/src/bench.rs): warm up, find how many calls take a
 * sample of about 20 ms, take 15 samples, and report the median time per
 * call with the fastest and slowest samples.
 *
 * Around the samples it also reads what the process spent (bench.rs
 * documents the fields): `cpu`, process CPU time per call from getrusage();
 * `peak`, the most Zend heap the samples held above what was live when they
 * started (memory_get_peak_usage() after memory_reset_peak_usage());
 * `retained`, live heap growth per call (memory_get_usage()); and `rss`, the
 * driver's peak resident memory so far (ru_maxrss).
 */
final class Bench
{
    public const SAMPLES = 15;

    private const SAMPLE_NS = 20_000_000;

    private const WARMUP_NS = 200_000_000;

    /**
     * @param callable(): mixed $bench
     * @return array{ns: float, min: float, max: float, samples: int, calls: int, cpu: float, peak: float, retained: float, rss: float}
     */
    public static function measure(callable $bench): array
    {
        $started = hrtime(true);
        $warm = 0;
        while (hrtime(true) - $started < self::WARMUP_NS || $warm < 10) {
            $bench();
            $warm++;
        }
        $n = 1;
        while (true) {
            $t = hrtime(true);
            self::run($bench, $n);
            $took = hrtime(true) - $t;
            if ($took >= self::SAMPLE_NS / 4 || $n >= 1 << 30) {
                break;
            }
            $n *= 2;
        }
        $perSample = max(1, (int) ($n * self::SAMPLE_NS / max(1, $took)));
        $samples = [];
        $live = memory_get_usage();
        memory_reset_peak_usage();
        $cpu = self::cpu();
        for ($i = 0; $i < self::SAMPLES; $i++) {
            $t = hrtime(true);
            self::run($bench, $perSample);
            $samples[] = (hrtime(true) - $t) / $perSample;
        }
        $cpu = self::cpu() - $cpu;
        $calls = self::SAMPLES * $perSample;
        $peak = memory_get_peak_usage() - $live;
        $retained = (memory_get_usage() - $live) / $calls;
        sort($samples);

        return [
            'ns' => (float) $samples[intdiv(\count($samples), 2)],
            'min' => (float) $samples[0],
            'max' => (float) $samples[\count($samples) - 1],
            'samples' => \count($samples),
            'calls' => $perSample,
            'cpu' => $cpu / $calls,
            'peak' => (float) max(0, $peak),
            'retained' => (float) $retained,
            'rss' => (float) (getrusage()['ru_maxrss'] ?? 0) * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024),
        ];
    }

    /**
     * CPU time the process has used (user and system), in nanoseconds.
     */
    private static function cpu(): float
    {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1e9
            + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) * 1e3;
    }

    /**
     * @param callable(): mixed $bench
     */
    private static function run(callable $bench, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $bench();
        }
    }
}
