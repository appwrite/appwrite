<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\VCS\Adapter\Git;

/**
 * A provider the jobs service cannot clone is handed to the Builds worker
 * through the queue; a build that never reaches it must not stay waiting.
 */
final class QueueTest extends TestCase
{
    /**
     * @return \Iterator<string, array{\Closure}>
     */
    public static function refusals(): \Iterator
    {
        yield 'enqueue throws' => [static fn ($publisher) => $publisher->method('enqueue')->willThrowException(new \RuntimeException('Queue unavailable'))];
        yield 'enqueue returns false' => [static fn ($publisher) => $publisher->method('enqueue')->willReturn(false)];
    }

    #[DataProvider('refusals')]
    public function testAnUnqueuedBuildFailsTheDeployment(\Closure $refuse): void
    {
        $deployment = new Document(['$id' => 'deployment', 'type' => 'vcs', 'providerCommitHash' => 'abc123']);

        $database = $this->createStub(Database::class);
        $database->method('createDocument')->willReturnCallback(static function (string $collection, Document $document) use ($deployment): Document {
            $deployment->setAttributes([...$document->getArrayCopy(), '$sequence' => '1']);
            return clone $deployment;
        });
        $database->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $update) use ($deployment): int {
            $deployment->setAttributes($update->getArrayCopy());
            return 1;
        });

        $publisher = $this->createStub(BuildPublisher::class);
        $refuse($publisher);

        $git = $this->createStub(Git::class);
        $git->method('supportsRepositoryArchives')->willReturn(false);
        $git->method('supportsRepositoryCloneUrl')->willReturn(false);

        $deployments = new Deployments(new Jobs($this->createStub(ClientInterface::class)), $database, new Document(['$id' => 'project', 'region' => 'default']), ['apiHostname' => 'localhost'], $publisher);

        $refused = false;
        try {
            $deployments->createFromVcs(new Document(['$id' => 'site', '$sequence' => '2', '$collection' => 'sites']), clone $deployment, 137, $git, 'acme', 'web', 'abc123');
        } catch (\RuntimeException|Exception) {
            $refused = true;
        }

        $this->assertTrue($refused, 'A build that was not queued must not be returned as submitted');
        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('internal error', (string) $deployment->getAttribute('buildLogs'));
        $this->assertNotEmpty($deployment->getAttribute('buildEndedAt'));
    }
}
