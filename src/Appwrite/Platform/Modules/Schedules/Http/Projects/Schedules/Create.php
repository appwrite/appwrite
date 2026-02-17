<?php

namespace Appwrite\Platform\Modules\Schedules\Http\Projects\Schedules;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Task\Validator\Cron;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSchedule';
    }

    protected function getResourceTypes(): array
    {
        return [
            SCHEDULE_RESOURCE_TYPE_FUNCTION,
            SCHEDULE_RESOURCE_TYPE_EXECUTION,
            SCHEDULE_RESOURCE_TYPE_MESSAGE,
        ];
    }

    protected function getCollection(string $resourceType): string
    {
        return match ($resourceType) {
            SCHEDULE_RESOURCE_TYPE_FUNCTION => 'functions',
            SCHEDULE_RESOURCE_TYPE_EXECUTION => 'executions',
            SCHEDULE_RESOURCE_TYPE_MESSAGE => 'messages',
            default => throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid resource type: ' . $resourceType),
        };
    }

    protected function getNotFoundException(string $resourceType): string
    {
        return match ($resourceType) {
            SCHEDULE_RESOURCE_TYPE_FUNCTION => Exception::FUNCTION_NOT_FOUND,
            SCHEDULE_RESOURCE_TYPE_EXECUTION => Exception::EXECUTION_NOT_FOUND,
            SCHEDULE_RESOURCE_TYPE_MESSAGE => Exception::MESSAGE_NOT_FOUND,
            default => Exception::GENERAL_ARGUMENT_INVALID,
        };
    }

    public function __construct()
    {
        $resourceTypes = $this->getResourceTypes();

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/projects/:projectId/schedules')
            ->desc('Create schedule')
            ->groups(['api', 'projects'])
            ->label('scope', 'schedules.write')
            ->label('event', 'schedules.[scheduleId].create')
            ->label('audits.event', 'schedule.create')
            ->label('audits.resource', 'schedule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'schedules',
                name: 'createSchedule',
                description: '/docs/references/schedules/create.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SCHEDULE,
                    ),
                ],
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('resourceType', '', new WhiteList($resourceTypes, true), 'The resource type for the schedule. Possible values: '.implode(', ', $resourceTypes).'.')
            ->param('resourceId', '', new UID(), 'The resource ID to associate with this schedule.')
            ->param('schedule', '', new Cron(), 'Schedule CRON expression.')
            ->param('active', false, new Boolean(), 'Whether the schedule is active.', true)
            ->param('data', null, new JSON(), 'Schedule data as a JSON string. Used to store resource-specific context needed for execution.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        string $resourceType,
        string $resourceId,
        string $schedule,
        bool $active,
        ?string $data,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Event $queueForEvents,
    ): void {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $dbForProject = $getProjectDB($project);

        $collection = $this->getCollection($resourceType);
        $resource = $dbForProject->getDocument($collection, $resourceId);

        if ($resource->isEmpty()) {
            throw new Exception($this->getNotFoundException($resourceType), 'Resource not found');
        }

        $attributes = [
            'region' => $project->getAttribute('region'),
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'resourceInternalId' => $resource->getSequence(),
            'resourceUpdatedAt' => DateTime::now(),
            'projectId' => $project->getId(),
            'schedule' => $schedule,
            'active' => $active,
        ];

        if ($data !== null) {
            $attributes['data'] = \json_decode($data, true);
        }

        try {
            $doc = $dbForPlatform->createDocument('schedules', new Document($attributes));
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('scheduleId', $doc->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($doc, Response::MODEL_SCHEDULE);
    }
}
