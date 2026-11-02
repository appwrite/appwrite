<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Phone;

use Appwrite\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePhone';
    }
}
