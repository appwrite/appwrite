<?php

namespace Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe;

use Utopia\Database\Operator;

class Delete extends Base
{
    public static function getName(): string
    {
        return 'deleteAlertsUnsubscribe';
    }

    protected function getMethod(): string
    {
        return self::HTTP_REQUEST_METHOD_DELETE;
    }

    protected function change(array $unsubscribed, string $scope): ?Operator
    {
        // Removes every copy of the scope, including one a concurrent request added twice.
        return \in_array($scope, $unsubscribed, true) ? Operator::arrayRemove($scope) : null;
    }
}
