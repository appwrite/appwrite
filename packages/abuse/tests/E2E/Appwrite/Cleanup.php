<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests\E2E\Appwrite;

use RuntimeException;
use Throwable;

final class Cleanup extends RuntimeException
{
    public function __construct(public readonly Throwable $setup, public readonly Throwable $cleanup)
    {
        parent::__construct('Fixture setup and cleanup both failed: ' . $cleanup->getMessage(), previous: $setup);
    }
}
