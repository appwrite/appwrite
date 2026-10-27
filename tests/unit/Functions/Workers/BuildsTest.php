<?php

declare(strict_types=1);

namespace Tests\Unit\Functions\Workers;

use Appwrite\Deployment\Deployments;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Functions\Workers\Builds;
use Appwrite\Usage\Context;
use Appwrite\Vcs\Factory as VcsFactory;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Utopia\Config\Config;
use Utopia\Console\Command;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Queue\Message;
use Utopia\Storage\Device\Local;
use Utopia\VCS\Adapter\Git;

final class BuildsTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $directories = [];

    public function testARefusedTemplateBuildKeepsItsReasonAndIsNotRetried(): void
    {
        $function = new Document([
            '$id' => 'function',
            '$sequence' => '10',
            '$collection' => 'functions',
            'name' => 'Starter',
            'runtime' => array_key_first(Config::getParam('runtimes-v2')),
        ]);
        $deployment = new Document([
            '$id' => 'deployment',
            '$sequence' => '1',
            'resourceType' => 'functions',
            'type' => 'vcs',
            'entrypoint' => 'index.js',
            'installationId' => 'installation',
            'providerRepositoryId' => '42',
            'providerBranch' => 'main',
            'providerCommitHash' => 'abc123',
            'status' => 'waiting',
            'activate' => true,
        ]);
        $documents = ['function' => $function, 'deployment' => $deployment];

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(static fn (string $collection, string $id) => clone $documents[$id]);
        $dbForProject->method('findOne')->willReturnCallback(static fn () => clone $deployment);
        $dbForProject->method('updateDocument')->willReturnCallback(static function (string $collection, string $id, Document $update) use ($documents): Document {
            $documents[$id]->setAttributes($update->getArrayCopy());
            return clone $documents[$id];
        });
        $dbForProject->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $update) use ($deployment): int {
            if ($deployment->getAttribute('status') === 'canceled') {
                return 0;
            }
            $deployment->setAttributes($update->getArrayCopy());
            return 1;
        });

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document(['$id' => 'installation', 'providerInstallationId' => '7']));
        $dbForPlatform->method('findOne')->willReturn(new Document());

        $commitStates = [];
        $git = $this->createStub(Git::class);
        $git->method('getOwnerName')->willReturn('owner');
        $git->method('getRepositoryName')->willReturn('repository');
        $git->method('generateCloneCommand')->willReturn(new Command('true'));
        $git->method('supportsRepositoryArchives')->willReturn(true);
        $git->method('getRepositoryPresignedUrl')->willReturn('https://example.com/archive.tar.gz');
        $git->method('updateCheck')->willReturnCallback(static function (string $owner, string $repository, string $sha, string $state) use (&$commitStates): void {
            $commitStates[] = $state;
        });

        $vcsFactory = $this->createStub(VcsFactory::class);
        $vcsFactory->method('fromProvider')->willReturn($git);
        $vcsFactory->method('fromInstallation')->willReturn($git);

        $project = new Document(['$id' => 'project', '$sequence' => '5', 'name' => 'Project', 'region' => 'default']);
        $deployments = new readonly class (new Jobs($this->createStub(ClientInterface::class)), $dbForProject, $project, ['apiHostname' => 'localhost'], $this->createStub(BuildPublisher::class)) extends Deployments {
            protected function admit(Document $resource, Document $deployment): void
            {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Deployment limit reached');
            }
        };

        (new Builds())->action(
            new Message([
                'pid' => 'message',
                'queue' => 'builds',
                'timestamp' => \time(),
                'payload' => [
                    'type' => BUILD_TYPE_DEPLOYMENT,
                    'resource' => $function->getArrayCopy(),
                    'deployment' => $deployment->getArrayCopy(),
                    'template' => [],
                    'platform' => ['apiHostname' => 'localhost'],
                ],
            ]),
            $project,
            $dbForPlatform,
            $this->createStub(Realtime::class),
            new Context(),
            $this->createStub(UsagePublisher::class),
            $vcsFactory,
            $dbForProject,
            static fn () => false,
            $deployments,
        );

        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('Deployment limit reached', (string) $deployment->getAttribute('buildLogs'));
        $this->assertStringNotContainsString('internal error', (string) $deployment->getAttribute('buildLogs'));
        $this->assertSame(['failure'], $commitStates, 'The commit must be reported as failed');
    }

    public function testTemplateIsNotMergedIntoALinkedRepositoryRootDirectory(): void
    {
        $outside = $this->directory('outside');

        [$deployment, $error] = $this->buildTemplate(
            rootDirectory: 'app',
            templateRootDirectory: '',
            checkout: static function (string $directory) use ($outside): void {
                if (\str_ends_with($directory, '/template')) {
                    \file_put_contents($directory . '/payload.js', 'payload');
                    return;
                }
                \symlink($outside, $directory . '/app');
            },
        );

        $this->assertFileDoesNotExist($outside . '/payload.js', 'The template was written through the linked root directory');
        $this->assertNull($error, 'A refused root directory is a build failure, not a worker error');
        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('must not be a symbolic link', (string) $deployment->getAttribute('buildLogs'));
    }

    public function testALinkedTemplateRootDirectoryIsNotMerged(): void
    {
        $outside = $this->directory('outside');
        \file_put_contents($outside . '/secret.txt', 'secret');
        $merged = '';

        [$deployment, $error] = $this->buildTemplate(
            rootDirectory: 'app',
            templateRootDirectory: 'starter',
            checkout: static function (string $directory) use ($outside, &$merged): void {
                if (\str_ends_with($directory, '/template')) {
                    \symlink($outside, $directory . '/starter');
                    return;
                }
                \mkdir($directory . '/app');
                $merged = $directory . '/app/secret.txt';
            },
        );

        $this->assertFileDoesNotExist($merged, 'The linked template root directory was copied into the repository');
        $this->assertNull($error, 'A refused root directory is a build failure, not a worker error');
        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('must not be a symbolic link', (string) $deployment->getAttribute('buildLogs'));
    }

    public function testALinkInsideARepositoryRootDirectoryPathIsNotFollowed(): void
    {
        $outside = $this->directory('outside');
        \mkdir($outside . '/app');

        [$deployment, $error] = $this->buildTemplate(
            rootDirectory: 'apps/app',
            templateRootDirectory: '',
            checkout: static function (string $directory) use ($outside): void {
                if (\str_ends_with($directory, '/template')) {
                    \file_put_contents($directory . '/payload.js', 'payload');
                    return;
                }
                \symlink($outside, $directory . '/apps');
            },
        );

        $this->assertFileDoesNotExist($outside . '/app/payload.js', 'The template was written through a linked parent directory');
        $this->assertNull($error, 'A refused root directory is a build failure, not a worker error');
        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('must not be a symbolic link', (string) $deployment->getAttribute('buildLogs'));
    }

    /**
     * Runs a template deployment of a repository-connected function, with
     * both clones replaced by $checkout writing the checked-out tree.
     *
     * @param callable(string): void $checkout
     * @return array{Document, ?\Throwable} the deployment, and what the worker threw
     */
    private function buildTemplate(string $rootDirectory, string $templateRootDirectory, callable $checkout): array
    {
        $deploymentId = 'deployment' . \bin2hex(\random_bytes(6));
        $this->directories[] = '/tmp/builds/' . $deploymentId;

        $function = new Document([
            '$id' => 'function',
            '$sequence' => '10',
            '$collection' => 'functions',
            'name' => 'Starter',
            'runtime' => array_key_first(Config::getParam('runtimes-v2')),
            'providerRootDirectory' => $rootDirectory,
        ]);
        $deployment = new Document([
            '$id' => $deploymentId,
            '$sequence' => '1',
            'resourceType' => 'functions',
            'type' => 'vcs',
            'entrypoint' => 'index.js',
            'installationId' => 'installation',
            'providerRepositoryId' => '42',
            'providerBranch' => 'main',
            'status' => 'waiting',
            'activate' => true,
        ]);
        $documents = ['function' => $function, $deploymentId => $deployment];

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(static fn (string $collection, string $id) => clone $documents[$id]);
        $dbForProject->method('findOne')->willReturnCallback(static fn () => clone $deployment);
        $dbForProject->method('updateDocument')->willReturnCallback(static function (string $collection, string $id, Document $update) use ($documents): Document {
            $documents[$id]->setAttributes($update->getArrayCopy());
            return clone $documents[$id];
        });
        $dbForProject->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $update) use ($deployment): int {
            $deployment->setAttributes($update->getArrayCopy());
            return 1;
        });

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document(['$id' => 'installation', 'providerInstallationId' => '7']));
        $dbForPlatform->method('findOne')->willReturn(new Document());

        $git = $this->createStub(Git::class);
        $git->method('getOwnerName')->willReturn('owner');
        $git->method('getRepositoryName')->willReturn('repository');
        $git->method('generateCloneCommand')->willReturnCallback(static function (string $owner, string $repositoryName, string $version, string $versionType, string $directory) use ($checkout): Command {
            \mkdir($directory, 0o755, true);
            $checkout($directory);
            return new Command('true');
        });

        $vcsFactory = $this->createStub(VcsFactory::class);
        $vcsFactory->method('fromProvider')->willReturn($git);
        $vcsFactory->method('fromInstallation')->willReturn($git);

        $project = new Document(['$id' => 'project', '$sequence' => '5', 'name' => 'Project', 'region' => 'default']);

        $error = null;
        try {
            (new Builds())->action(
                new Message([
                    'pid' => 'message',
                    'queue' => 'builds',
                    'timestamp' => \time(),
                    'payload' => [
                        'type' => BUILD_TYPE_DEPLOYMENT,
                        'resource' => $function->getArrayCopy(),
                        'deployment' => $deployment->getArrayCopy(),
                        'template' => [
                            'repositoryName' => 'templates',
                            'ownerName' => 'appwrite',
                            'referenceType' => 'branch',
                            'referenceValue' => 'main',
                            'rootDirectory' => $templateRootDirectory,
                        ],
                        'platform' => ['apiHostname' => 'localhost'],
                    ],
                ]),
                $project,
                $dbForPlatform,
                $this->createStub(Realtime::class),
                new Context(),
                $this->createStub(UsagePublisher::class),
                $vcsFactory,
                $dbForProject,
                static fn () => false,
                new Deployments(new Jobs($this->createStub(ClientInterface::class)), $dbForProject, $project, ['apiHostname' => 'localhost'], $this->createStub(BuildPublisher::class)),
            );
        } catch (\Throwable $thrown) {
            $error = $thrown;
        }

        return [$deployment, $error];
    }

    private function directory(string $name): string
    {
        $directory = \realpath(\sys_get_temp_dir()) . '/builds-' . $name . '-' . \bin2hex(\random_bytes(6));
        \mkdir($directory);
        $this->directories[] = $directory;

        return $directory;
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            (new Local())->delete($directory, true);
        }
        $this->directories = [];

        parent::tearDown();
    }
}
