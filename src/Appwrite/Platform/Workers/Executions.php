<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Appwrite\Event\Message\Executions as ExecutionsMessage;
use Exception;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;

class Executions extends Action
{
    private const int UPSERT_BATCH_SIZE = 100;

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
        $payload = $message->getPayload();
        $isBatch = isset($payload['executions']) && \is_array($payload['executions']);

        if ($isBatch) {
            $executionMessage = ExecutionsMessage::fromArray($payload);
            $executions = \array_values(\array_filter(
                $executionMessage->executions,
                fn ($execution) => !$execution->isEmpty()
            ));
        } else {
            $executionMessage = Execution::fromArray($payload);
            $executions = [$executionMessage->execution];
        }

        if (empty($executions)) {
            throw new Exception($isBatch ? 'Missing executions' : 'Missing execution');
        }

        Span::add('project.id', $executionMessage->project->getId());

        if ($isBatch) {
            Span::add('executions.count', \count($executions));
            $dbForProject->upsertDocuments('executions', $executions, self::UPSERT_BATCH_SIZE);
        } else {
            $execution = $executions[0];
            Span::add('function.id', $execution->getAttribute('resourceId', ''));
            Span::add('execution.id', $execution->getId());
            Span::add('deployment.id', $execution->getAttribute('deploymentId', ''));
            Span::add('resource.type', $execution->getAttribute('resourceType', ''));
            $dbForProject->upsertDocument('executions', $execution);
        }
    }
}
