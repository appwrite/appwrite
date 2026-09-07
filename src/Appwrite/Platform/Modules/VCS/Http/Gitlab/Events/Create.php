<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitlab\Events;

use Appwrite\Platform\Modules\VCS\Http\Events\Base;

class Create extends Base
{
    public static function getName()
    {
        return 'createVCSGitlabEvent';
    }

    public static function getProvider(): string
    {
        return 'gitlab';
    }

    public static function getProviderName(): string
    {
        return 'GitLab';
    }

    protected function getCommitEmails(): array
    {
        return [APP_VCS_GITLAB_EMAIL];
    }

    protected function getPushEvents(): array
    {
        return ['Push Hook'];
    }

    protected function getPullRequestEvents(): array
    {
        return ['Merge Request Hook'];
    }
}
