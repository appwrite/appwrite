<?php

namespace Appwrite\Platform\Modules\Schedules\Http\Schedules;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getSchedule';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/schedules/:scheduleId')
            ->desc('Get schedule')
            ->groups(['api', 'schedules'])
            ->label('scope', 'schedules.read')
            ->label('sdk', new Method(
                namespace: 'schedules',
                group: 'schedules',
                name: 'get',
                description: '/docs/references/schedules/get.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SCHEDULE,
                    )
                ]
            ))
            ->param('scheduleId', '', new UID(), 'Schedule ID.')
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $scheduleId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $schedule = $authorization->skip(
            fn () => $dbForPlatform->getDocument('schedules', $scheduleId)
        );

        if ($schedule->isEmpty()) {
            throw new Exception(Exception::SCHEDULE_NOT_FOUND);
        }

        if ($schedule->getAttribute('projectId') !== $project->getId()) {
            throw new Exception(Exception::SCHEDULE_NOT_FOUND);
        }

        $response->dynamic($schedule, Response::MODEL_SCHEDULE);
    }
}
