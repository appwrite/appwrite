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

        $execution = $dbForProject->getDocument('executions', $executionId);
        if ($execution->isEmpty()) {
            // A scheduled execution can be cancelled before its document has
            // been persisted by the executions worker. Remove the schedule and
            // dispatch ExecutionCancelled so the worker removes the document
            // if it lands later.
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

            $cancelled = $authorization->skip(fn () => $this->cancelSchedule($dbForPlatform, $schedule->getId()));
            if (!$cancelled) {
                throw new Exception(Exception::EXECUTION_NOT_FOUND);
            }

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
            return;
        }

        if ($execution->getAttribute('resourceType') !== 'functions' || $execution->getAttribute('resourceInternalId') !== $function->getSequence()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }
        $status = $execution->getAttribute('status');

        // Treat timed-out executions as failed so they can be deleted.
        if ($status === 'waiting' || $status === 'processing') {
            $timeout = $function->getAttribute('timeout', 900);
            $elapsed = \time() - \strtotime($execution->getCreatedAt());
            if ($elapsed >= $timeout) {
                $status = 'failed';
            }
        }

        if (!in_array($status, ['completed', 'failed', 'scheduled'])) {
            throw new Exception(Exception::EXECUTION_IN_PROGRESS);
        }

        if ($status === 'scheduled') {
            $schedule = $authorization->skip(fn () => $dbForPlatform->findOne('schedules', [
                Query::equal('resourceId', [$execution->getId()]),
                Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_EXECUTION]),
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::equal('active', [true]),
            ]));

            if ($schedule->isEmpty()) {
                throw new Exception(Exception::EXECUTION_IN_PROGRESS);
            }

            $cancelled = $authorization->skip(fn () => $this->cancelSchedule($dbForPlatform, $schedule->getId()));
            if (!$cancelled) {
                throw new Exception(Exception::EXECUTION_IN_PROGRESS);
            }

            // Route cancellation through the executions queue so it is ordered
            // after the scheduled insert. The schedule lock ensures no delayed
            // function execution can be published afterward.
            $bus->dispatch(new ExecutionCancelled(
                execution: $execution->getArrayCopy(),
                project: $project->getArrayCopy(),
            ));
        } elseif (!$dbForProject->deleteDocument('executions', $execution->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove execution from DB');
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setPayload($response->output($execution, Response::MODEL_EXECUTION));

        $response->noContent();
    }

    private function cancelSchedule(Database $dbForPlatform, string $scheduleId): bool
    {
        return $dbForPlatform->withTransaction(function () use ($dbForPlatform, $scheduleId) {
            $schedule = $dbForPlatform->getDocument('schedules', $scheduleId, forUpdate: true);

            // active=false is the scheduler's durable claim. Cancellation
            // loses once that claim is committed, even if queue publication
            // succeeds and the scheduler later fails to remove the schedule.
            if ($schedule->isEmpty() || !$schedule->getAttribute('active', false)) {
                return false;
            }

            return $dbForPlatform->deleteDocument('schedules', $scheduleId);
        });
    }
}
