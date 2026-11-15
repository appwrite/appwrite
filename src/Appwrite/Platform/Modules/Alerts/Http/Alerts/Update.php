<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Alerts;

use Appwrite\Alert\Acknowledgements;
use Appwrite\Event\Event;
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
use Utopia\Validator\Boolean;

class Update extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateAlert';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/alerts/:alertId')
            ->desc('Update alert')
            ->groups(['api', 'alerts'])
            ->label('scope', 'alerts.write')
            ->label('audits.event', 'alert.update')
            ->label('audits.resource', 'alert/{request.alertId}')
            ->label('sdk', new Method(
                namespace: 'alerts',
                group: null,
                name: 'update',
                description: <<<EOT
                Acknowledge a firing alert, or undo that. Everyone who can read the alert sees who acknowledged it and when. The alert keeps firing until its monitor recovers; a resolved alert keeps its acknowledgement and cannot be changed.
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
            ->param('acknowledged', false, new Boolean(), 'Whether the alert is acknowledged. False undoes an acknowledgement.')
            ->inject('response')
            ->inject('project')
            ->inject('user')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $alertId,
        bool $acknowledged,
        Response $response,
        Document $project,
        Document $user,
        Database $dbForPlatform,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $alert = $this->getAlert($dbForPlatform, $authorization, $project, $alertId);
        $updated = (new Acknowledgements($dbForPlatform))->set($alert, $acknowledged, $user);

        $change = Acknowledgements::change($alert, $updated);
        if ($change !== null) {
            $queueForEvents
                ->setEvent('monitors.[monitorId].alerts.[alertId].' . $change->value)
                ->setParam('monitorId', (string) $updated->getAttribute('monitorId'))
                ->setParam('alertId', $updated->getId());
        }

        $response->dynamic($updated, Response::MODEL_ALERT);
    }
}
