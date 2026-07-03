<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects\Root;

use Appwrite\Platform\Modules\Console\Http\Redirects\Base;

class Get extends Base
{
    public static function getName(): string
    {
        return 'consoleRedirectRoot';
    }

    protected function getPath(): string
    {
        return '/';
    }
}
