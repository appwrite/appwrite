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
    public const UNKNOWN = 'unknown';
}
