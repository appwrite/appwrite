<?php

namespace Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe;

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

    protected function apply(array $unsubscribed, string $scope): array
    {
        return [...$unsubscribed, $scope];
    }
}
