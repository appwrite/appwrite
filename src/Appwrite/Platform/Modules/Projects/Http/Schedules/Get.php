<?php

namespace Appwrite\Platform\Modules\Projects\Http\Schedules;

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
        return 'getSchedule';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/projects/:projectId/schedules/:scheduleId')
            ->desc('Get schedule')
            ->groups(['api', 'projects'])
            ->label('scope', 'schedules.read')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'schedules',
                name: 'getSchedule',
                description: '/docs/references/projects/get-schedule.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SCHEDULE,
                    )
                ],
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('scheduleId', '', new UID(), 'Schedule ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        string $scheduleId,
        Response $response,
        Database $dbForPlatform,
    ): void {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $schedule = $dbForPlatform->getDocument('schedules', $scheduleId);

        if ($schedule->isEmpty()) {
            throw new Exception(Exception::SCHEDULE_NOT_FOUND);
        }

        if ($schedule->getAttribute('projectId') !== $project->getId()) {
            throw new Exception(Exception::SCHEDULE_NOT_FOUND);
        }

        $response->dynamic($schedule, Response::MODEL_SCHEDULE);
    }
}
