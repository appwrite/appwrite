<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors;

use Appwrite\Alert\Schedules;
use Appwrite\Alert\State;
use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteMonitor';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/monitors/:monitorId')
            ->desc('Delete monitor')
            ->groups(['api', 'alerts'])
            ->label('scope', 'monitors.write')
            ->label('audits.event', 'monitor.delete')
            ->label('audits.resource', 'monitor/{request.monitorId}')
            ->label('sdk', new Method(
                namespace: 'monitors',
                group: null,
                name: 'delete',
                description: <<<EOT
                Delete a monitor by its unique ID. It stops being evaluated; an alert it is firing is resolved and kept with the rest of its alert history.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
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

        // Stopped first: if the delete then fails the monitor is still there to retry,
        // while a schedule left active after the monitor is gone would fire forever.
        (new Schedules($dbForPlatform))->stop($monitor);

        $alert = $authorization->skip(fn () => $dbForPlatform->getDocument('alerts', (string) $monitor->getAttribute('alertId', '')));
        if (!$alert->isEmpty() && $alert->getAttribute('status') === State::Firing->value) {
            $authorization->skip(fn () => $dbForPlatform->updateDocument('alerts', $alert->getId(), new Document([
                'status' => State::Resolved->value,
                'resolvedAt' => DateTime::now(),
            ])));
        }

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('monitors', $monitor->getId()));

        $response->noContent();
    }
}
