<?php

namespace Tests\Compat\Queue;

/**
 * The fixed clock both runtimes' brokers read during a case, so envelopes,
 * keys and tokens are byte-identical: `time()` is pinned, `uniqid()` counts
 * (`<prefix>0001`, ...), and `random_bytes()` counts too (16 ASCII digits,
 * which the broker hex-encodes into its delivery token).
 *
 * The broker calls these unqualified from its namespace, so the functions
 * declared in Functions.php shadow the global ones while the clock is fixed.
 */
final class Clock
{
    public static ?int $time = null;

    public static string $prefix = 'pid';

    public static int $ids = 0;

    public static int $tokens = 0;

    /**
     * Every clock pinned in this process, by prefix: its time and counters.
     *
     * @var array<string, array{int, int, int}>
     */
    private static array $pinned = [];

    /**
     * `$continue` moves a pinned clock to another time, keeping its counters:
     * those of the clock already pinned under `$prefix`, else the current one's.
     */
    public static function fix(int $time, string $prefix, bool $continue = false): void
    {
        self::save();
        if (!$continue || self::$time === null) {
            self::$ids = 0;
            self::$tokens = 0;
        } elseif (isset(self::$pinned[$prefix])) {
            // Continuing a clock of another prefix resumes that clock's counters.
            [, self::$ids, self::$tokens] = self::$pinned[$prefix];
        }
        self::$time = $time;
        self::$prefix = $prefix;
        self::save();
    }

    /** The namespace of the case the pinned clocks belong to. */
    private static string $case = '';

    /** Forgets the clocks pinned by an earlier case. */
    public static function scope(string $ns): void
    {
        if ($ns !== self::$case) {
            self::$case = $ns;
            self::$pinned = [];
        }
    }

    /** Switches to the clock pinned under `$prefix`, if there is one. */
    public static function activate(string $prefix): void
    {
        self::save();
        if (isset(self::$pinned[$prefix])) {
            [self::$time, self::$ids, self::$tokens] = self::$pinned[$prefix];
            self::$prefix = $prefix;
        }
    }

    private static function save(): void
    {
        if (self::$time !== null) {
            self::$pinned[self::$prefix] = [self::$time, self::$ids, self::$tokens];
        }
    }

    public static function release(): void
    {
        self::$time = null;
    }

    public static function time(): int
    {
        return self::$time ?? \time();
    }

    public static function uniqid(string $prefix, bool $moreEntropy): string
    {
        if (self::$time === null) {
            return \uniqid($prefix, $moreEntropy);
        }

        return \sprintf('%s%04d', self::$prefix, ++self::$ids);
    }

    public static function randomBytes(int $length): string
    {
        if (self::$time === null || $length < 1) {
            return \random_bytes(max(1, $length));
        }

        return str_pad((string) ++self::$tokens, $length, '0', STR_PAD_LEFT);
    }
}
