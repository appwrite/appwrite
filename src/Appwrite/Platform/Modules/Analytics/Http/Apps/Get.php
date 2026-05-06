<?php

namespace Appwrite\Platform\Modules\Analytics\Http\Apps;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getAnalyticsApp';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/analytics/apps/:appId')
            ->desc('Get analytics app')
            ->groups(['api', 'analytics'])
            ->label('scope', 'analytics.read')
            ->label('sdk', new Method(
                namespace: 'analytics',
                group: 'apps',
                name: 'getApp',
                description: 'Get an analytics app by ID.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANALYTICS_APP,
                    ),
                ],
            ))
            ->param('appId', '', new UID(), 'Analytics app unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        Response $response,
        Database $dbForProject,
    ): void {
        $app = $dbForProject->getDocument('analyticsApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $response->dynamic($app, Response::MODEL_ANALYTICS_APP);
    }
}
