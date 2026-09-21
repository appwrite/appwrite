<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Appwrite\Event\Message\ExecutionCancelled as ExecutionCancelledMessage;
use Appwrite\Event\Message\Executions as ExecutionsMessage;
use Appwrite\Execution\Store;
use Exception;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;

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
            ->inject('executionStore')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Store $executionStore,
    ): void {
        $payload = $message->getPayload();

        if (($payload['operation'] ?? '') === 'delete') {
            $executionMessage = ExecutionCancelledMessage::fromArray($payload);
            $execution = $executionMessage->execution;

            if ($execution->isEmpty()) {
                throw new Exception('Missing execution');
            }

            Span::add('project.id', $executionMessage->project->getId());
            Span::add('execution.id', $execution->getId());
            Span::add('execution.cancelled', true);

            $executionStore->delete($executionMessage->project->getId(), $execution);

            return;
        }

        $isBatch = isset($payload['executions']) && \is_array($payload['executions']);

        if ($isBatch) {
            $executionMessage = ExecutionsMessage::fromArray($payload);
            $executions = \array_values(\array_filter(
                $executionMessage->executions,
                fn ($execution) => !$execution->isEmpty()
            ));
        } else {
            $executionMessage = Execution::fromArray($payload);
            $executions = \array_values(\array_filter(
                [$executionMessage->execution],
                fn ($execution) => !$execution->isEmpty()
            ));
        }

        if (empty($executions)) {
            throw new Exception($isBatch ? 'Missing executions' : 'Missing execution');
        }

        Span::add('project.id', $executionMessage->project->getId());

        if ($isBatch) {
            Span::add('executions.count', \count($executions));
            $executionStore->upsertMany($executionMessage->project->getId(), $executions);

            return;
        }

        $execution = $executions[0];
        Span::add('function.id', $execution->getAttribute('resourceId', ''));
        Span::add('execution.id', $execution->getId());
        Span::add('deployment.id', $execution->getAttribute('deploymentId', ''));
        Span::add('resource.type', $execution->getAttribute('resourceType', ''));

        $executionStore->upsert($executionMessage->project->getId(), $execution);
    }
}
