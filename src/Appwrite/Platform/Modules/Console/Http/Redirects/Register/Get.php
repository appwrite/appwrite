<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects\Register;

use Appwrite\Platform\Modules\Console\Http\Redirects\Base;

class Get extends Base
{
    public static function getName(): string
    {
        return 'consoleRedirectRegister';
    }

    protected function getPath(): string
    {
        return '/register/*';
    }
}
