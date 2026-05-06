<?php

namespace Appwrite\Platform\Modules\Account\Http\Alerts;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Alerts;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listAlerts';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/alerts')
            ->desc('List alerts')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'alerts',
                name: 'listAlerts',
                description: '/docs/references/account/list-alerts.md',
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ALERT_LIST,
                    )
                ]
            ))
            ->param('queries', [], new Alerts(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Alerts::ALLOWED_ATTRIBUTES), true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('user')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        array $queries,
        Response $response,
        Database $dbForPlatform,
        Document $user
    ): void {
        $response->dynamic(new Document([
            'alerts' => [],
            'total' => 0,
        ]), Response::MODEL_ALERT_LIST);
    }
}
