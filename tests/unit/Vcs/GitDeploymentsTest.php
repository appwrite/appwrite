<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Deployment\Deployments;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\VCS\Adapter\Git;

final class GitDeploymentsTest extends TestCase
{
    /** @var array<string, Document> The project database's deployments, by id */
    private array $deployments = [];

    public function testARefusedPushIsReportedAsFailedToTheProvider(): void
    {
        // Signs the build's API key, so a deployment that slips past the
        // refusal reaches the job submission instead of failing on its payload.
        $previousKey = getenv('_APP_OPENSSL_KEY_V1');
        $previousPools = Config::getParam('pools-database', []);
        putenv('_APP_OPENSSL_KEY_V1=unit-test-key');
        Config::setParam('pools-database', ['db_main']);
        try {
            $this->assertRefusedPush();
        } finally {
            putenv($previousKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousKey);
            Config::setParam('pools-database', $previousPools);
        }
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
        $dbForProject->method('find')->willReturn([]);

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
        $git->method('updateCommitStatus')->willReturnCallback(static function (string $repository, string $sha, string $owner, string $state) use (&$commitStates): void {
            $commitStates[] = $state;
        });
        $git->method('getComment')->willReturnCallback(static function () use (&$comment): string {
            return $comment;
        });
        $git->method('updateComment')->willReturnCallback(static function (string $owner, string $repository, string $id, string $body) use (&$comment): string {
            $comment = $body;
            return $id;
        });

        $requests = 0;
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use (&$requests): Response {
            $requests++;
            return new Response(202, body: new Stream('{"id":"build","status":"accepted"}'));
        });

        $platform = ['apiHostname' => 'localhost', 'consoleUrl' => 'https://console.localhost', 'consoleHostname' => 'console.localhost'];
        $deploymentsFactory = static fn (Database $dbForProject, Document $project): Deployments => new readonly class (new Jobs($client), $dbForProject, $project, $platform) extends Deployments {
            protected function admit(Document $resource, Document $deployment): void
            {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Deployment limit reached');
            }
        };

        (new GitDeployments())->push(
            $git,
            '7',
            [$repository],
            'main',
            'https://github.com/owner/repository/tree/main',
            'repository',
            'https://github.com/owner/repository',
            'owner',
            'abc123',
            'author',
            'https://github.com/author',
            'Update index.js',
            'https://github.com/owner/repository/commit/abc123',
            '',
            [],
            false,
            $dbForPlatform,
            new Authorization(),
            $this->createStub(Bus::class),
            static fn () => $dbForProject,
            $platform,
            $deploymentsFactory,
        );

        $this->assertCount(1, $this->deployments);
        $this->assertSame('failed', \current($this->deployments)->getAttribute('status'));
        $this->assertSame(['failure'], $commitStates, 'The commit must be reported as failed');
        $this->assertStringContainsString('_Failed_', $comment, 'The PR comment must show the build failed');
        $this->assertStringNotContainsString('_Queued_', $comment, 'The PR comment must not wait on a build that never runs');
        $this->assertSame(0, $requests, 'No build job may be submitted');
    }
}

final class GitDeployments
{
    use Deployment;

    /**
     * @param array<Document> $repositories
     * @param array<string> $providerAffectedFiles
     * @param array<string, mixed> $platform
     */
    public function push(
        Git $vcs,
        string $providerInstallationId,
        array $repositories,
        string $providerBranch,
        string $providerBranchUrl,
        string $providerRepositoryName,
        string $providerRepositoryUrl,
        string $providerRepositoryOwner,
        string $providerCommitHash,
        string $providerCommitAuthor,
        string $providerCommitAuthorUrl,
        string $providerCommitMessage,
        string $providerCommitUrl,
        string $providerPullRequestId,
        array $providerAffectedFiles,
        bool $external,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ): void {
        $this->createGitDeployments(...\func_get_args());
    }
}
