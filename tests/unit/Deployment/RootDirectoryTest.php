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
 * A root directory stored before validation can still leave the repository,
 * so a VCS build refuses it instead of handing it to the jobs service.
 */
final class RootDirectoryTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string}>
     */
    public static function escaping(): \Iterator
    {
        yield 'parent' => ['..'];
        yield 'leading parent' => ['../other'];
        yield 'anchored parent' => ['./../other'];
        yield 'inner parent' => ['apps/../../other'];
    }

    #[DataProvider('escaping')]
    public function testEscapingRootDirectoryFailsTheDeployment(string $rootDirectory): void
    {
        $deployment = new Document(['$id' => 'deployment', 'type' => 'vcs', 'status' => 'waiting']);

        $database = $this->createStub(Database::class);
        $database->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $update) use ($deployment): int {
            $deployment->setAttributes($update->getArrayCopy());
            return 1;
        });

        $git = $this->createMock(Git::class);
        $git->expects($this->never())->method('supportsRepositoryArchives');
        $git->expects($this->never())->method('supportsRepositoryCloneUrl');

        $publisher = $this->createMock(BuildPublisher::class);
        $publisher->expects($this->never())->method('enqueue');

        $deployments = new Deployments(new Jobs($this->createStub(ClientInterface::class)), $database, new Document(['$id' => 'project', 'region' => 'default']), ['apiHostname' => 'localhost'], $publisher);

        try {
            $deployments->createFromVcs(new Document(['$id' => 'site', '$collection' => 'sites']), clone $deployment, 137, $git, 'acme', 'web', 'main', $rootDirectory);
            $this->fail('An escaping root directory must be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $error->getType());
        }

        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('Root directory', (string) $deployment->getAttribute('buildLogs'));
    }
}
