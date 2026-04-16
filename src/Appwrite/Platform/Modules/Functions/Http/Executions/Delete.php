<?php

namespace Appwrite\Platform\Modules\Functions\Http\Executions;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
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
            ->label('scope', 'execution.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('event', 'functions.[functionId].executions.[executionId].delete')
            ->label('audits.event', 'executions.delete')
            ->label('audits.resource', 'function/{request.functionId}')
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
            ->param('functionId', '', new UID(), 'Function ID.')
            ->param('executionId', '', new UID(), 'Execution ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $executionId,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Event $queueForEvents,
        Authorization $authorization
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $execution = $dbForProject->getDocument('executions', $executionId);
        if ($execution->isEmpty()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        if ($execution->getAttribute('resourceType') !== 'functions' && $execution->getAttribute('resourceInternalId') !== $function->getSequence()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }
        $status = $execution->getAttribute('status');

        if (!in_array($status, ['completed', 'failed', 'scheduled'])) {
            throw new Exception(Exception::EXECUTION_IN_PROGRESS);
        }

        if (!$dbForProject->deleteDocument('executions', $execution->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove execution from DB');
        }

        if ($status === 'scheduled') {
            $schedule = $dbForPlatform->findOne('schedules', [
                Query::equal('resourceId', [$execution->getId()]),
                Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_EXECUTION]),
                Query::equal('active', [true]),
            ]);

            if (!$schedule->isEmpty()) {
                $schedule
                    ->setAttribute('resourceUpdatedAt', DateTime::now())
                    ->setAttribute('active', false);

                $authorization->skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));
            }
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setPayload($response->output($execution, Response::MODEL_EXECUTION));

        $response->noContent();
    }
}
