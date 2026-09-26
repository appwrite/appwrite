<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Tests\Services;
use Utopia\VCS\Adapter\Git\Gogs;

final class GogsTest extends GiteaBase
{
    protected static string $owner = '';
    protected static string $defaultBranch = 'master';
    protected static bool $supportsPullRequestCreation = false;
    protected static bool $supportsPullRequestLookup = false;
    protected static bool $supportsCommitStatuses = false;
    protected static bool $supportsCommitStatusLookup = false;
    protected static bool $supportsRepositoryLanguages = false;

    /** The adapter creates a repository with an initial commit, never an empty one. */
    protected static bool $createsEmptyRepositories = false;

    protected function setupAdapter(): void
    {
        $adapter = new Gogs(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: Services::token('gogs'),
            refreshToken: '',
        );
        $adapter->setEndpoint(Services::GOGS_URL);

        if (self::$owner === '') {
            self::$owner = $adapter->createOrganization('test-org-' . uniqid());
        }

        $this->vcsAdapter = $adapter;
    }

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return Services::GOGS_URL . '/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }
}
