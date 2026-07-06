<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Events;

use Appwrite\Platform\Modules\VCS\Http\Events\Base;

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
}
