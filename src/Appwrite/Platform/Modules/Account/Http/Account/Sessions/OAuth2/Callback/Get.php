<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Callback;

use Appwrite\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2Callback';
    }
}
