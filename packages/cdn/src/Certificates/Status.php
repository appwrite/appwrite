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
    /**
     * The certificate expires soon and the provider, which renews it on its
     * own, has not replaced it yet. It keeps serving until it expires, and
     * ordering another one does not help while the provider owns the renewal.
     */
    public const EXPIRING = 'expiring';
    public const UNKNOWN = 'unknown';
}
