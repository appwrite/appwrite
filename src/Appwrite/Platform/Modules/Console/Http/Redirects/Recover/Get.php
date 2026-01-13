<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects\Recover;

use Appwrite\Platform\Modules\Console\Http\Redirects\Base;

class Get extends Base
{
    public static function getName(): string
    {
        return 'consoleRedirectRecover';
    }

    protected function getPath(): string
    {
        return '/recover';
    }
}
