<?php

namespace Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe;

use Utopia\Database\Operator;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createAlertsUnsubscribe';
    }

    protected function getMethod(): string
    {
        return self::HTTP_REQUEST_METHOD_POST;
    }

    protected function change(array $unsubscribed, string $entry): ?Operator
    {
        // A concurrent request for the same entry can still add it twice, which reads the same.
        return \in_array($entry, $unsubscribed, true) ? null : Operator::arrayAppend([$entry]);
    }
}
