<?php

namespace Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe;

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

    protected function apply(array $unsubscribed, string $scope): array
    {
        return \array_values(\array_diff($unsubscribed, [$scope]));
    }
}
