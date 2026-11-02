<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Tokens\OAuth2;

use Appwrite\Platform\Modules\Account\Base;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createOAuth2Token';
    }
}
