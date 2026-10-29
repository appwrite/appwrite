<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

/**
 * Swallows writes to STDERR for the rest of the process. A process-isolated test fails on any
 * child stderr, and the broker logs its expected fail-closed errors there, so the test mutes it.
 */
final class MutedStderr extends \php_user_filter
{
    private const NAME = 'muted.stderr';

    public static function mute(): void
    {
        if (!\in_array(self::NAME, \stream_get_filters(), true)) {
            \stream_filter_register(self::NAME, self::class);
        }

        \stream_filter_append(STDERR, self::NAME, STREAM_FILTER_WRITE);
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = \stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
