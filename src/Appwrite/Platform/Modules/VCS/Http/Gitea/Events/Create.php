<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Events;

use Appwrite\Platform\Modules\VCS\Http\Events\Base;

class Create extends Base
{
    public static function getName()
    {
        return 'createVCSGiteaEvent';
    }

    public static function getProvider(): string
    {
        return 'gitea';
    }

    public static function getProviderName(): string
    {
        return 'Gitea';
    }

    protected function getCommitEmail(): string
    {
        return APP_VCS_GITEA_EMAIL;
    }

    protected function getPushEvents(): array
    {
        return ['push'];
    }

    protected function getPullRequestEvents(): array
    {
        return ['pull_request'];
    }
}
