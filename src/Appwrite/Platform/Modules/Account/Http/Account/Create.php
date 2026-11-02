<?php

namespace Appwrite\Platform\Modules\Account\Http\Account;

use Appwrite\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'create';
    }
}
