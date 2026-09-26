<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Tests\Services;
use Utopia\VCS\Adapter\Git\Gitea;

final class GiteaTest extends GiteaBase
{
    protected static string $owner = '';

    protected function setupAdapter(): void
    {
        $adapter = new Gitea(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: Services::token('gitea'),
            refreshToken: '',
        );
        $adapter->setEndpoint(Services::GITEA_URL);

        if (self::$owner === '') {
            self::$owner = $adapter->createOrganization('test-org-' . uniqid());
        }

        $this->vcsAdapter = $adapter;
    }

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return Services::GITEA_URL . '/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }
}
