<?php

declare(strict_types=1);

namespace Utopia\Cdn\Certificates;

class Status
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const ISSUED = 'issued';
    public const RENEWING = 'renewing';
    public const FAILED = 'failed';
    /**
     * Issuance is waiting on the domain owner, typically for a DNS record that
     * proves ownership. Reported through `Exception\Certificate` rather than
     * returned, so a caller polling for completion cannot mistake it for progress.
     */
    public const BLOCKED = 'blocked';
    public const UNKNOWN = 'unknown';
}
