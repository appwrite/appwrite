<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Build;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class BuildTest extends TestCase
{
    private function message(?int $timeout): Build
    {
        return new Build(
            project: new Document(['$id' => 'project-id', '$sequence' => '42']),
            resource: new Document(['$id' => 'site-id', '$collection' => 'sites']),
            deployment: new Document(['$id' => 'deployment-id']),
            type: BUILD_TYPE_DEPLOYMENT,
            template: new Document(),
            platform: ['apiHostname' => 'localhost'],
            timeout: $timeout,
        );
    }

    public function testTimeoutReachesTheWorkerPayload(): void
    {
        $payload = $this->message(137)->toArray();

        $this->assertSame(137, $payload['timeout']);
        $this->assertSame(137, Build::fromArray($payload)->timeout);
    }

    public function testNoTimeoutLeavesTheWorkerDefault(): void
    {
        $payload = $this->message(null)->toArray();

        // The Builds worker falls back to _APP_COMPUTE_BUILD_TIMEOUT on null
        $this->assertNull($payload['timeout']);
        $this->assertNull(Build::fromArray($payload)->timeout);
        $this->assertNull(Build::fromArray(['type' => BUILD_TYPE_DEPLOYMENT])->timeout);
    }
}
