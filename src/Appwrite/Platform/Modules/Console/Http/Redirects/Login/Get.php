<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects\Login;

use Appwrite\Platform\Modules\Console\Http\Redirects\Base;

class Get extends Base
{
    public static function getName(): string
    {
        return 'consoleRedirectLogin';
    }

    protected function getPath(): string
    {
        return '/login';
    }
}
