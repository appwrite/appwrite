<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Deployment\Deployments;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\VCS\Adapter\Git;

final class GitDeploymentsTest extends TestCase
{
    /** @var array<string, Document> The project database's deployments, by id */
    private array $deployments = [];

    public function testARefusedPushIsReportedAsFailedToTheProvider(): void
    {
        $previousPools = Config::getParam('pools-database', []);
        Config::setParam('pools-database', ['db_main']);
        try {
            $this->assertRefusedPush();
        } finally {
            Config::setParam('pools-database', $previousPools);
        }
    }

    public function testAPullRequestOnABuiltBranchLinksTheDeploymentItsPushMade(): void
    {
        $previousPools = Config::getParam('pools-database', []);
        Config::setParam('pools-database', ['db_main']);
        try {
            $this->assertPullRequestComment();
        } finally {
            Config::setParam('pools-database', $previousPools);
        }
    }

    /**
     * A pull request, or trail, opens on a branch whose push already built:
     * the comment pass deploys nothing, so its comment must show that
     * deployment, rendered for a provider that displays no images.
     */
    private function assertPullRequestComment(): void
    {
        $project = new Document(['$id' => 'project', '$sequence' => '5', 'name' => 'Project', 'database' => 'db_main', 'region' => 'default']);
        $function = new Document([
            '$id' => 'function',
            '$sequence' => '10',
            '$collection' => 'functions',
            'name' => 'Starter',
            'repositoryId' => 'repository',
            'providerBranch' => 'main',
        ]);
        $repository = new Document([
            '$id' => 'repository',
            '$sequence' => '20',
            'projectId' => 'project',
            'resourceId' => 'function',
            'resourceType' => 'function',
            'providerRepositoryId' => '42',
            'installationId' => 'installation',
            'installationInternalId' => '30',
        ]);
        $pushed = new Document(['$id' => 'pushed', '$sequence' => '40', 'status' => 'ready']);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturn($function);
        $dbForProject->method('findOne')->willReturn($pushed);
        $dbForProject->method('createDocument')->willReturnCallback(function (string $collection, Document $document): Document {
            $this->deployments[$document->getId()] = $document;
            return $document;
        });

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn($project);
        $dbForPlatform->method('findOne')->willReturn(new Document());

        $comment = '';
        $git = $this->createStub(Git::class);
        $git->method('getName')->willReturn('entire');
        $git->method('getOwnerName')->willReturn('owner');
        $git->method('getRepositoryName')->willReturn('repository');
        $git->method('supportsCommentImages')->willReturn(false);
        $git->method('createComment')->willReturnCallback(static function (string $owner, string $repository, int $number, string $body) use (&$comment): string {
            $comment = $body;
            return $number . '-discussion';
        });

        (new GitDeployments())->push(
            vcs: $git,
            providerInstallationId: '7',
            repositories: [$repository],
            providerBranch: 'feature',
            providerBranchUrl: '',
            providerRepositoryName: 'repository',
            providerRepositoryUrl: '',
            providerRepositoryOwner: 'owner',
            providerCommitHash: 'abc123',
            providerCommitAuthor: '',
            providerCommitAuthorUrl: '',
            providerCommitMessage: 'Add a pricing page',
            providerCommitUrl: '',
            providerPullRequestId: '5',
            providerAffectedFiles: [],
            external: false,
            dbForPlatform: $dbForPlatform,
            authorization: new Authorization(),
            bus: $this->createStub(Bus::class),
            getProjectDB: static fn () => $dbForProject,
            platform: ['apiHostname' => 'localhost', 'consoleUrl' => 'https://console.localhost', 'consoleHostname' => 'console.localhost'],
            deploymentsFactory: static fn () => throw new \LogicException('A comment pass must not deploy'),
        );

        $this->assertSame([], $this->deployments, 'The comment pass must not deploy');
        $this->assertStringContainsString('/functions/function/deployments/pushed)', $comment, 'The comment must link the deployment the push made');
        $this->assertStringContainsString('_Ready_', $comment);
        $this->assertStringNotContainsString('<picture>', $comment, 'A provider that displays no images must get none');
    }

    private function assertRefusedPush(): void
    {
        $project = new Document(['$id' => 'project', '$sequence' => '5', 'name' => 'Project', 'database' => 'db_main', 'region' => 'default']);
        $function = new Document([
            '$id' => 'function',
            '$sequence' => '10',
            '$collection' => 'functions',
            'name' => 'Starter',
            'runtime' => array_key_first(Config::getParam('runtimes-v2')),
            'entrypoint' => 'index.js',
            'repositoryId' => 'repository',
            'providerBranch' => 'main',
        ]);
        $repository = new Document([
            '$id' => 'repository',
            '$sequence' => '20',
            'projectId' => 'project',
            'resourceId' => 'function',
            'resourceType' => 'function',
            'providerRepositoryId' => '42',
            'installationId' => 'installation',
            'installationInternalId' => '30',
        ]);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(function (string $collection, string $id) use ($function): Document {
            return clone ($collection === 'deployments' ? $this->deployments[$id] : $function);
        });
        $dbForProject->method('createDocument')->willReturnCallback(function (string $collection, Document $document): Document {
            $document->setAttribute('$sequence', '1');
            $this->deployments[$document->getId()] = $document;
            return clone $document;
        });
        $dbForProject->method('updateDocuments')->willReturnCallback(function (string $collection, Document $update): int {
            foreach ($this->deployments as $deployment) {
                $deployment->setAttributes($update->getArrayCopy());
            }
            return \count($this->deployments);
        });

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn($project);
        $dbForPlatform->method('find')->willReturn([new Document(['providerCommentId' => 'comment'])]);
        $dbForPlatform->method('findOne')->willReturn(new Document());

        $commitStates = [];
        $comment = '';
        $git = $this->createStub(Git::class);
        $git->method('getName')->willReturn('github');
        $git->method('getOwnerName')->willReturn('owner');
        $git->method('getRepositoryName')->willReturn('repository');
        $git->method('supportsRepositoryArchives')->willReturn(true);
        $git->method('getRepositoryPresignedUrl')->willReturn('https://example.com/archive.tar.gz');
        $git->method('updateCheck')->willReturnCallback(static function (string $owner, string $repository, string $sha, string $state) use (&$commitStates): void {
            $commitStates[] = $state;
        });
        $git->method('getComment')->willReturnCallback(static function () use (&$comment): string {
            return $comment;
        });
        $git->method('updateComment')->willReturnCallback(static function (string $owner, string $repository, string $id, string $body) use (&$comment): string {
            $comment = $body;
            return $id;
        });

        $platform = ['apiHostname' => 'localhost', 'consoleUrl' => 'https://console.localhost', 'consoleHostname' => 'console.localhost'];
        $client = $this->createStub(ClientInterface::class);
        $publisherForBuilds = $this->createStub(BuildPublisher::class);
        $deploymentsFactory = static fn (Database $dbForProject, Document $project): Deployments => new readonly class (new Jobs($client), $dbForProject, $project, $platform, $publisherForBuilds) extends Deployments {
            protected function admit(Document $resource, Document $deployment): void
            {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Deployment limit reached');
            }
        };

        (new GitDeployments())->push(
            vcs: $git,
            providerInstallationId: '7',
            repositories: [$repository],
            providerBranch: 'main',
            providerBranchUrl: '',
            providerRepositoryName: 'repository',
            providerRepositoryUrl: '',
            providerRepositoryOwner: 'owner',
            providerCommitHash: 'abc123',
            providerCommitAuthor: '',
            providerCommitAuthorUrl: '',
            providerCommitMessage: 'Update index.js',
            providerCommitUrl: '',
            providerPullRequestId: '',
            providerAffectedFiles: [],
            external: false,
            dbForPlatform: $dbForPlatform,
            authorization: new Authorization(),
            bus: $this->createStub(Bus::class),
            getProjectDB: static fn () => $dbForProject,
            platform: $platform,
            deploymentsFactory: $deploymentsFactory,
        );

        $this->assertSame(['failure'], $commitStates, 'The commit must be reported as failed');
        $this->assertStringContainsString('_Failed_', $comment, 'The PR comment must show the build failed');
        $this->assertStringNotContainsString('_Queued_', $comment, 'The PR comment must not wait on a build that never runs');
    }
}

final class GitDeployments
{
    use Deployment;

    public function push(mixed ...$arguments): void
    {
        $this->createGitDeployments(...$arguments);
    }
}
