<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects\Auth;

use Appwrite\Platform\Modules\Console\Http\Redirects\Base;

class Get extends Base
{
    public static function getName(): string
    {
        return 'consoleRedirectAuth';
    }

    protected function getPath(): string
    {
        return '/auth/*';
    }
}
