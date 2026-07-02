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
}
