<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\Bitbucket;

final class BitbucketTest extends Base
{
    protected static string $accessToken = '';
    protected static string $owner = '';
    // Bitbucket names an empty repository's first branch 'master'
    protected static string $defaultBranch = 'master';

    protected static bool $supportsInstallationRepository = false;
    protected static bool $supportsRepositoryLanguages = false;

    // Bitbucket has no repository to resolve an owner from; getOwnerName()
    // reports the account the token belongs to
    protected static bool $resolvesOwnerFromRepositoryId = false;

    // Workspaces have no personal-vs-team distinction to report as 'kind'
    protected static bool $reportsNamespaceKinds = false;

    // Accounts are looked up by uuid, not by handle
    protected static bool $supportsUserLookup = false;

    // Bitbucket Cloud can't reach a local test catcher
    protected static bool $supportsWebhookDelivery = false;

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return 'https://bitbucket.org/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }

    protected function setupAdapter(): void
    {
        if (self::$accessToken === '' || self::$accessToken === '0') {
            self::$accessToken = System::getEnv('TESTS_BITBUCKET_ACCESS_TOKEN') ?? '';
            $this->markTestSkipped('Bitbucket access token not configured');
        }

        $adapter = new Bitbucket(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: self::$accessToken,
            refreshToken: '',
        );

        if (self::$owner === '' || self::$owner === '0') {
            // Fall back to the token's own workspace when none is configured
            self::$owner = System::getEnv('TESTS_BITBUCKET_WORKSPACE') ?: $adapter->getOwnerName('');
        }

        $this->vcsAdapter = $adapter;
    }

    /**
     * Bitbucket reports a repository's owner as the workspace holding it.
     *
     * @param array<string, mixed> $repository
     */
    #[\Override]
    protected function ownerOf(array $repository): string
    {
        $this->assertArrayHasKey('workspace', $repository);
        $this->assertIsArray($repository['workspace']);
        $this->assertArrayHasKey('slug', $repository['workspace']);

        return (string) $repository['workspace']['slug'];
    }

    /**
     * @return array<string, mixed>
     */

    /**
     * @return array<string, mixed>
     */

    /**
     * Bitbucket only names the author in a raw "Name <email>" string; a commit
     * linked to an account is named by the account instead.
     */

    /**
     * Bitbucket batches every ref a push touched into one delivery, and the
     * shared event shape describes a branch push, so tags are left out.
     */

}
