<?php

namespace Appwrite\Platform\Modules\Databases\Http\Init;

use Appwrite\Utopia\Request;
use Utopia\Database\Database;
use Utopia\Http;
use Utopia\Platform\Action;

/**
 * Project database timeout,
 */
class Timeout extends Action
{
    public static function getName(): string
    {
        return 'projectDatabaseTimeout';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_INIT)
            ->groups(['api', 'database'])
            ->inject('request')
            ->inject('dbForProject')
            ->callback(function (Request $request, Database $dbForProject) {
                $timeout = \intval($request->getHeader('x-appwrite-timeout'));

                if (!empty($timeout) && Http::isDevelopment()) {
                    $dbForProject->setTimeout($timeout);
                }
            });
    }
}
