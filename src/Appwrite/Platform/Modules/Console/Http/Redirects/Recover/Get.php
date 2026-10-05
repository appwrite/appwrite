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

    protected function getTarget(string $path, array $params): string
    {
        // The reset page requires the emailed userId and secret, without them only the request form is usable
        if (empty($params['userId']) || empty($params['secret'])) {
            return '/recovery';
        }

        return '/reset';
    }
}
