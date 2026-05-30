<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects\Card;

use Appwrite\Platform\Modules\Console\Http\Redirects\Base;

class Get extends Base
{
    public static function getName(): string
    {
        return 'consoleRedirectCard';
    }

    protected function getPath(): string
    {
        return '/card/*';
    }
}
