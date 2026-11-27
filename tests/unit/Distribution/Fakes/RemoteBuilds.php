<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Storage\Device;
use Utopia\Storage\Device\S3;

/**
 * Builds on an S3 distribution device. Building payloads never reaches it.
 */
final readonly class RemoteBuilds extends ExposedBuilds
{
    #[\Override]
    protected static function device(string $projectId): Device
    {
        return new S3("distribution/app-{$projectId}", 'access', 'secret', 'https://s3.example.test', 'us-east-1');
    }
}
