<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

final class GitHubTest extends Base
{
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';

    protected static string $avatarDomain = 'githubusercontent.com';
    protected static bool $supportsPullRequestCreation = false;
    protected static bool $supportsNamespaceListing = false;
    protected static bool $supportsCommitStatusLookup = false;
    protected static bool $supportsTags = false;
    protected static bool $supportsUserLookup = false;
    protected static bool $computesLanguagesAsynchronously = true;
    protected static bool $supportsWebhookDelivery = false;
    protected static bool $resolvesOwnerFromRepositoryId = false;
    protected static bool $rejectsInvalidRepositoryNames = false;

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return 'https://github.com/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }

    protected function setupAdapter(): void
    {
        $privateKey = str_replace('\\n', "\n", System::getEnv('TESTS_GITHUB_PRIVATE_KEY') ?? '');
        $appId = System::getEnv('TESTS_GITHUB_APP_IDENTIFIER') ?? '';
        self::$installationId = System::getEnv('TESTS_GITHUB_INSTALLATION_ID') ?? '';

        if (empty($privateKey) || ($appId === '' || $appId === '0') || (self::$installationId === '' || self::$installationId === '0')) {
            $this->markTestSkipped('GitHub App credentials not configured');
        }

        $adapter = new GitHub(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: self::$installationId,
            privateKey: $privateKey,
            appId: $appId,
            accessToken: '',
            refreshToken: '',
        );

        if (self::$owner === '' || self::$owner === '0') {
            self::$owner = $adapter->getOwnerName(self::$installationId);
        }

        $this->vcsAdapter = $adapter;
    }

    public function testListBranchesPagination(): void
    {
        $repositoryName = 'test-list-branches-pages-' . uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');
            $this->getLatestCommitEventually($repositoryName);
            $this->vcsAdapter->createBranch(self::$owner, $repositoryName, 'branch-a', self::$defaultBranch);
            $this->vcsAdapter->createBranch(self::$owner, $repositoryName, 'branch-b', self::$defaultBranch);

            /** @var GitHub $adapter */
            $adapter = $this->vcsAdapter;

            // Both branches have to be listable before paging through them
            $this->assertEventually(function () use ($adapter, $repositoryName): void {
                $this->assertCount(3, $adapter->listBranches(self::$owner, $repositoryName, 100, 1));
            });

            $page1 = $adapter->listBranches(self::$owner, $repositoryName, 1, 1);
            $this->assertSame(['branch-a'], $page1);

            $page2 = $adapter->listBranches(self::$owner, $repositoryName, 1, 2);
            $this->assertSame(['branch-b'], $page2);

            $all = $adapter->listBranches(self::$owner, $repositoryName, 100, 1);
            $this->assertEqualsCanonicalizing([self::$defaultBranch, 'branch-a', 'branch-b'], $all);

            $searchResults = $adapter->listBranches(self::$owner, $repositoryName, 100, 1, 'branch');
            $this->assertEqualsCanonicalizing(['branch-a', 'branch-b'], $searchResults);

            $noMatch = $adapter->listBranches(self::$owner, $repositoryName, 100, 1, 'xyz');
            $this->assertEmpty($noMatch);
        } finally {
            $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
        }
    }
}
