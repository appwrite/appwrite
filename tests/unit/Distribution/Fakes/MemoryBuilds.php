<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Storage\Device;

/**
 * Builds on a remote distribution device held in memory.
 */
final readonly class MemoryBuilds extends ExposedBuilds
{
    #[\Override]
    protected static function device(string $projectId): Device
    {
        return new MemoryDevice("distribution/app-{$projectId}");
    }
}
