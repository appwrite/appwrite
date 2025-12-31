<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects\Invite;

use Appwrite\Platform\Modules\Console\Http\Redirects\Base;

class Get extends Base
{
    public static function getName(): string
    {
        return 'consoleRedirectInvite';
    }

    protected function getPath(): string
    {
        return '/invite';
    }
}
