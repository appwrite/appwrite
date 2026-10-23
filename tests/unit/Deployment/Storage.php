<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Utopia\Database\Document;

final readonly class Storage extends Deployments
{
    public static function output(string $projectId, string $deploymentId): string
    {
        return static::objectUrl(static::device($projectId), static::buildPath($projectId, $deploymentId));
    }

    /**
     * The cache a build of the resource reads and writes on the local device.
     */
    public static function cache(Document $project, Document $resource): string
    {
        return static::storage($project, $resource, new Document(['$id' => 'deployment']))['environment']['OPEN_RUNTIMES_BUILD_CACHE_ARTIFACT'];
    }
}
