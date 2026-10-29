<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors;

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
        return 'getMonitor';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/monitors/:monitorId')
            ->desc('Get monitor')
            ->groups(['api', 'alerts'])
            ->label('scope', 'monitors.read')
            ->label('sdk', new Method(
                namespace: 'monitors',
                group: null,
                name: 'get',
                description: <<<EOT
                Get a monitor by its unique ID, with its state as of its last evaluation.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MONITOR,
                    ),
                ]
            ))
            ->param('monitorId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Monitor ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $monitorId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $monitor = $this->getMonitor($dbForPlatform, $authorization, $project, $monitorId);
        $monitor->removeAttribute('webhookSecret');

        $response->dynamic($monitor, Response::MODEL_MONITOR);
    }
}
