<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2;

use Appwrite\Platform\Modules\Account\Base;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createOAuth2Session';
    }
}
