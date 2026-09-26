<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Tests\Services;
use Utopia\VCS\Adapter\Git\GitLab;

final class GitLabTest extends Base
{
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';
    protected static string $openPullRequestState = 'opened';

    /**
     * Names GitLab delivers its webhooks under.
     */
    protected static string $pushEventName = 'Push Hook';
    protected static string $pullRequestEventName = 'Merge Request Hook';

    /** @var array<string> */
    protected static array $pullRequestOpenedActions = ['opened', 'synchronize'];

    protected static string $presignedTarballFragment = '/repository/archive.tar.gz?access_token=';
    protected static string $presignedZipballFragment = '/repository/archive.zip?access_token=';
    protected static string $repositoryNotFoundException = \Exception::class;
    protected static bool $deletesRepositoriesSynchronously = false;
    protected static bool $supportsCheckRuns = false;
    protected static bool $supportsInstallationRepository = false;
    protected static bool $reportsCommitAuthorAvatar = false;
    protected static bool $reportsCommitAuthorUrl = false;

    protected function setupAdapter(): void
    {
        $adapter = new GitLab(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: Services::token('gitlab'),
            refreshToken: '',
        );
        $adapter->setEndpoint(Services::GITLAB_URL);

        if (self::$owner === '' || self::$owner === '0') {
            // GitLab answers its health probe well before the API serves traffic, so
            // give the first call room to get past 502s rather than failing every test.
            $this->assertEventually(function () use ($adapter): void {
                self::$owner = $adapter->createOrganization('test-org-' . uniqid());
            }, 60000, 2000);
        }

        $this->vcsAdapter = $adapter;
    }

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return Services::GITLAB_URL . '/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }

    /**
     * GitLab owners are carried as "id:path", but it reports the path alone.
     */
    #[\Override]
    protected function ownerPath(): string
    {
        return explode(':', self::$owner)[1] ?? self::$owner;
    }

    /**
     * GitLab reports a project's owner as its namespace.
     *
     * @param array<string, mixed> $repository
     */
    #[\Override]
    protected function ownerOf(array $repository): string
    {
        $this->assertArrayHasKey('namespace', $repository);
        $this->assertIsArray($repository['namespace']);
        $this->assertArrayHasKey('path', $repository['namespace']);

        return (string) $repository['namespace']['path'];
    }

    /**
     * GitLab reports visibility as a string rather than a boolean flag.
     *
     * @param array<string, mixed> $repository
     */
    #[\Override]
    protected function isPrivate(array $repository): bool
    {
        $this->assertArrayHasKey('visibility', $repository);
        $this->assertIsString($repository['visibility']);

        return $repository['visibility'] === 'private';
    }

    /**
     * GitLab numbers merge requests per project, under 'iid'.
     *
     * @param array<string, mixed> $pullRequest
     */
    #[\Override]
    protected function pullRequestNumberOf(array $pullRequest): int
    {
        $this->assertArrayHasKey('iid', $pullRequest);
        $this->assertIsNumeric($pullRequest['iid']);

        return (int) $pullRequest['iid'];
    }

}
