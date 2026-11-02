<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Bulk;

use Appwrite\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteSessions';
    }
}
