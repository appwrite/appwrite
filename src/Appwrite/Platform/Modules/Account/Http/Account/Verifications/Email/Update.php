<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email;

use Appwrite\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateEmailVerification';
    }
}
