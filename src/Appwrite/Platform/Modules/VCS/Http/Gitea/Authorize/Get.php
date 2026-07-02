<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Authorize;

use Appwrite\Platform\Modules\VCS\Http\Authorize\Base;

class Get extends Base
{
    public static function getName()
    {
        return 'getVCSGiteaAuthorize';
    }

    public static function getProvider(): string
    {
        return 'gitea';
    }
}
