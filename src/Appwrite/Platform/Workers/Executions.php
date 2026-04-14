<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Appwrite\Extend\TraceFunctionExecution;
use Exception;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

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
        $executionMessage = Execution::fromArray($message->getPayload() ?? []);
        $execution = $executionMessage->execution;

        if ($execution->isEmpty()) {
            throw new Exception('Missing execution');
        }

        TraceFunctionExecution::log('executions_worker_upsert', [
            'projectId' => $executionMessage->project->getId(),
            'functionId' => $execution->getAttribute('resourceId', ''),
            'executionId' => $execution->getId(),
            'deploymentId' => $execution->getAttribute('deploymentId', ''),
            'resourceType' => $execution->getAttribute('resourceType', ''),
        ]);

        $dbForProject->upsertDocument('executions', $execution);
    }
}
