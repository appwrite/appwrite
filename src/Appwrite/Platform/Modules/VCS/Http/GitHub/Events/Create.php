<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Events;

use Appwrite\Platform\Modules\VCS\Http\Events\Base;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Base
{
    public static function getName()
    {
        return 'createVCSGitHubEvent';
    }

    public static function getProvider(): string
    {
        return 'github';
    }

    public static function getProviderName(): string
    {
        return 'GitHub';
    }

    protected function getCommitEmails(): array
    {
        return [APP_VCS_GITHUB_EMAIL];
    }

    protected function getPushEvents(): array
    {
        return [GitHub::EVENT_PUSH];
    }

    protected function getPullRequestEvents(): array
    {
        return [GitHub::EVENT_PULL_REQUEST];
    }

    protected function getInstallationEvents(): array
    {
        return [GitHub::EVENT_INSTALLATION];
    }

    /**
     * A GitHub app can be registered without a webhook secret.
     */
    protected function requiresWebhookSecret(): bool
    {
        return false;
    }

    protected function resolveAdapters(
        array $repositories,
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        array &$errors,
    ): array {
        return $this->resolveAdaptersFromDelivery($repositories, $parsedPayload, $vcsFactory);
    }
}
