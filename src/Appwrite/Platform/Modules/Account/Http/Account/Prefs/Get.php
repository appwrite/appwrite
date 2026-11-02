<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Prefs;

use Appwrite\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getPrefs';
    }
}
