<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;

final readonly class Storage extends Deployments
{
    public static function output(string $projectId, string $deploymentId): string
    {
        return static::objectUrl(static::device($projectId), static::buildPath($projectId, $deploymentId));
    }
}
