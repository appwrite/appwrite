<?php

namespace Appwrite\Platform\Modules\Functions\Http\Executions;

use Appwrite\Bus\Events\ExecutionCancelled;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteExecution';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/functions/:functionId/executions/:executionId')
            ->desc('Delete execution')
            ->groups(['api', 'functions'])
            ->label('scope', ['executions.write', 'execution.write'])
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('event', 'functions.[functionId].executions.[executionId].delete')
            ->label('audits.event', 'executions.delete')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'executions',
                name: 'deleteExecution',
                description: <<<EOT
                Delete a function execution by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('executionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Execution ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->inject('bus')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $executionId,
        Response $response,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Event $queueForEvents,
        Authorization $authorization,
        Bus $bus,
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        // Executions are not persisted by Server CE; only a pending scheduled
        // execution can be cancelled, through its schedule.
        $schedule = $authorization->skip(fn () => $dbForPlatform->findOne('schedules', [
            Query::equal('resourceId', [$executionId]),
            Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_EXECUTION]),
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('active', [true]),
        ]));

        if ($schedule->isEmpty()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        if (($schedule->getAttribute('data')['functionId'] ?? null) !== $function->getId()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        $authorization->skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'resourceUpdatedAt' => DateTime::now(),
            'active' => false,
        ])));

        $execution = new Document([
            '$id' => $executionId,
            '$createdAt' => DateTime::now(),
            '$updatedAt' => DateTime::now(),
            '$permissions' => [],
            'functionId' => $function->getId(),
            'resourceId' => $function->getId(),
            'resourceType' => 'functions',
            'deploymentId' => '',
            'trigger' => 'schedule',
            'status' => 'scheduled',
            'requestMethod' => '',
            'requestPath' => '',
            'requestHeaders' => [],
            'responseStatusCode' => 0,
            'responseBody' => '',
            'responseHeaders' => [],
            'logs' => '',
            'errors' => '',
            'duration' => 0.0,
        ]);

        $bus->dispatch(new ExecutionCancelled(
            execution: $execution->getArrayCopy(),
            project: $project->getArrayCopy(),
        ));

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $executionId)
            ->setPayload($response->output($execution, Response::MODEL_EXECUTION));

        $response->noContent();
    }
}
