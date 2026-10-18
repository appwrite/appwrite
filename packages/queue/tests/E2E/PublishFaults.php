<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests\E2E;

/**
 * What a faulty transport still owes a publish test: how many publishes to fail,
 * and the reply it has queued for the next read.
 */
final class PublishFaults
{
    public bool $stale = false;

    public string $injected = '';

    /** @var array<string, string> inbox subscription subject => sid */
    public array $inboxes = [];

    /** Fetch requests to let through before a receive fault fires. */
    public int $skip = 0;

    /** Sockets the transport has opened. */
    public int $connects = 0;

    public function __construct(public int $faults)
    {
    }
}
