<?php

namespace Tests\Compat;

/**
 * Times a benchmark closure the way the Rust driver does
 * (crates/tools/compat/src/bench.rs): warm up, find how many calls take a
 * sample of about 20 ms, take 15 samples, and report the median time per
 * call with the fastest and slowest samples.
 */
final class Bench
{
    public const SAMPLES = 15;

    private const SAMPLE_NS = 20_000_000;

    private const WARMUP_NS = 200_000_000;

    /**
     * @param callable(): mixed $bench
     * @return array{ns: float, min: float, max: float, samples: int, calls: int}
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
        for ($i = 0; $i < self::SAMPLES; $i++) {
            $t = hrtime(true);
            self::run($bench, $perSample);
            $samples[] = (hrtime(true) - $t) / $perSample;
        }
        sort($samples);

        return [
            'ns' => (float) $samples[intdiv(\count($samples), 2)],
            'min' => (float) $samples[0],
            'max' => (float) $samples[\count($samples) - 1],
            'samples' => \count($samples),
            'calls' => $perSample,
        ];
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
