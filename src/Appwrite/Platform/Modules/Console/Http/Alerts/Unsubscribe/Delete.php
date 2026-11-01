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

    protected function change(array $unsubscribed, string $entry): ?Operator
    {
        // Removes every copy of the entry, including one a concurrent request added twice.
        return \in_array($entry, $unsubscribed, true) ? Operator::arrayRemove($entry) : null;
    }
}
