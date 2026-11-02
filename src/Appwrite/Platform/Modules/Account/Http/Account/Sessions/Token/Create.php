<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Token;

use Appwrite\Platform\Modules\Account\Base;
use Utopia\Platform\Scope\HTTP;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSession';
    }
}
