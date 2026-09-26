<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

/**
 * Forgejo and Gogs are Gitea forks: they answer the same API and report the
 * same shapes, so the family shares what it can support.
 */
abstract class GiteaBase extends Base
{
    protected static string $existingUser = 'utopia';
    protected static string $userHandleField = 'login';

    /** @var array<string> */
    protected static array $pullRequestOpenedActions = ['opened', 'synchronized'];

    protected static string $presignedTarballFragment = '.tar.gz?token=';
    protected static string $presignedZipballFragment = '.zip?token=';

    protected static string $avatarDomain = 'gravatar.com';
    protected static bool $supportsCheckRuns = false;
    protected static bool $supportsNamespaceListing = false;
    protected static bool $supportsInstallationRepository = false;
    protected static bool $reportsCommitAuthorUrl = false;
}
