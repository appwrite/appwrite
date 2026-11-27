<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests\E2E;

/**
 * Forgejo and Gogs are Gitea forks: they answer the same API and report the
 * same shapes, so the family shares what it can support.
 */
abstract class GiteaBase extends Base
{
    #[\Override]
    protected static string $existingUser = 'utopia';
    #[\Override]
    protected static string $userHandleField = 'login';

    /** @var array<string> */
    #[\Override]
    protected static array $pullRequestOpenedActions = ['opened', 'synchronized'];

    #[\Override]
    protected static string $presignedTarballFragment = '.tar.gz?token=';
    #[\Override]
    protected static string $presignedZipballFragment = '.zip?token=';

    #[\Override]
    protected static string $avatarDomain = 'gravatar.com';
    #[\Override]
    protected static bool $supportsCheckRuns = false;
    #[\Override]
    protected static bool $supportsNamespaceListing = false;
    #[\Override]
    protected static bool $supportsInstallationRepository = false;
    #[\Override]
    protected static bool $reportsCommitAuthorUrl = false;
}
