<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Tests\Services;
use Utopia\VCS\Adapter\Git\Forgejo;

final class ForgejoTest extends GiteaBase
{
    protected static string $owner = '';
    protected static string $avatarDomain = '/avatars/';

    protected function setupAdapter(): void
    {
        $adapter = new Forgejo(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: Services::token('forgejo'),
            refreshToken: '',
        );
        $adapter->setEndpoint(Services::FORGEJO_URL);

        if (self::$owner === '') {
            self::$owner = $adapter->createOrganization('test-org-' . uniqid());
        }

        $this->vcsAdapter = $adapter;
    }

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return Services::FORGEJO_URL . '/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }
}
