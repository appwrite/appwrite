<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Phone;

use Appwrite\Platform\Modules\Account\Base;
use Utopia\Platform\Scope\HTTP;

class Update extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePhoneSession';
    }
}
