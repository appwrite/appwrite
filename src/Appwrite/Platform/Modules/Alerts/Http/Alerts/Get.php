<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Alerts;

use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'getAlert';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/alerts/:alertId')
            ->desc('Get alert')
            ->groups(['api', 'alerts'])
            ->label('scope', 'alerts.read')
            ->label('sdk', new Method(
                namespace: 'alerts',
                group: null,
                name: 'get',
                description: <<<EOT
                Get an alert by its unique ID: the monitor that raised it, when it fired and resolved, and the readings while it fired.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ALERT,
                    ),
                ]
            ))
            ->param('alertId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Alert ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $alertId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $alert = $this->getAlert($dbForPlatform, $authorization, $project, $alertId);

        $response->dynamic($alert, Response::MODEL_ALERT);
    }
}
