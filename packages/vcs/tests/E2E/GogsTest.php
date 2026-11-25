<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Gogs;
use Utopia\VCS\Tests\Services;

final class GogsTest extends GiteaBase
{
    #[\Override]
    protected static string $owner = '';
    #[\Override]
    protected static string $defaultBranch = 'master';
    #[\Override]
    protected static bool $supportsPullRequestCreation = false;
    #[\Override]
    protected static bool $supportsPullRequestLookup = false;
    #[\Override]
    protected static bool $supportsCommitStatuses = false;
    #[\Override]
    protected static bool $supportsCommitStatusLookup = false;
    #[\Override]
    protected static bool $supportsRepositoryLanguages = false;

    /** The adapter creates a repository with an initial commit, never an empty one. */
    #[\Override]
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
