<?php

namespace Appwrite\Platform\Modules\VCS\Http\Bitbucket\Events;

use Appwrite\Platform\Modules\VCS\Http\Events\Base;

class Create extends Base
{
    public static function getName()
    {
        return 'createVCSBitbucketEvent';
    }

    public static function getProvider(): string
    {
        return 'bitbucket';
    }

    public static function getProviderName(): string
    {
        return 'Bitbucket';
    }

    protected function getCommitEmails(): array
    {
        return [APP_VCS_BITBUCKET_EMAIL];
    }

    protected function getPushEvents(): array
    {
        return ['repo:push'];
    }

    protected function getPullRequestEvents(): array
    {
        return ['pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected'];
    }
}
