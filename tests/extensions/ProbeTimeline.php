<?php

namespace Appwrite\Tests;

final class ProbeTimeline
{
    private const string FILE = '/tmp/probe-test-events.log';

    public static function record(string $event, string $test): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        \file_put_contents(
            self::FILE,
            $now->format('Y-m-d\TH:i:s.u') . '000Z TEST ' . $event . ' ' . $test . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
