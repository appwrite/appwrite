<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Exception;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\System\System;

class Executions extends Action
{
    public static function getName(): string
    {
        return 'executions';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Executions worker')
            ->groups(['executions'])
            ->inject('message')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Database $dbForProject,
    ): void {
        $executionMessage = Execution::fromArray($message->getPayload());
        $execution = $executionMessage->execution;

        if ($execution->isEmpty()) {
            throw new Exception('Missing execution');
        }

        $traceProjectId = System::getEnv('_APP_TRACE_PROJECT_ID', '');
        $traceFunctionId = System::getEnv('_APP_TRACE_FUNCTION_ID', '');
        $resourceId = $execution->getAttribute('resourceId', '');
        if ($traceProjectId !== '' && $traceFunctionId !== '' && $executionMessage->project->getId() === $traceProjectId && $resourceId === $traceFunctionId) {
            Span::init('execution.trace.executions_worker_upsert');
            Span::add('datetime', gmdate('c'));
            Span::add('projectId', $executionMessage->project->getId());
            Span::add('functionId', $resourceId);
            Span::add('executionId', $execution->getId());
            Span::add('deploymentId', $execution->getAttribute('deploymentId', ''));
            Span::add('resourceType', $execution->getAttribute('resourceType', ''));
            Span::current()?->finish();
        }

        $dbForProject->upsertDocument('executions', $execution);
    }
}
