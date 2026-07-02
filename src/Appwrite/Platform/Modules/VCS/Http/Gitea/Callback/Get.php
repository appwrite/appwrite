<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Callback;

use Appwrite\Platform\Modules\VCS\Http\Callback\Base;

class Get extends Base
{
    public static function getName()
    {
        return 'getVCSGiteaCallback';
    }

    public static function getProvider(): string
    {
        return 'gitea';
    }
}
