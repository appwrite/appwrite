<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Appwrite\Event\Message\ExecutionCancelled as ExecutionCancelledMessage;
use Appwrite\Event\Message\Executions as ExecutionsMessage;
use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;

class Executions extends Action
{
    private const int UPSERT_BATCH_SIZE = 100;

    private const array PENDING_STATUSES = ['waiting', 'processing', 'scheduled'];

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

        if (($payload['operation'] ?? '') === 'delete') {
            $executionMessage = ExecutionCancelledMessage::fromArray($payload);
            $execution = $executionMessage->execution;

            if ($execution->isEmpty()) {
                throw new Exception('Missing execution');
            }

            Span::add('project.id', $executionMessage->project->getId());
            Span::add('execution.id', $execution->getId());
            Span::add('execution.cancelled', true);

            if (!$dbForProject->deleteDocument('executions', $execution->getId())) {
                throw new Exception('Failed to remove cancelled execution');
            }

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

            $pending = \array_values(\array_filter($executions, $this->isPending(...)));
            $final = \array_values(\array_filter($executions, fn (Document $execution) => !$this->isPending($execution)));

            foreach ($pending as $execution) {
                $this->create($dbForProject, $execution);
            }

            if (!empty($final)) {
                $dbForProject->upsertDocuments('executions', $final, self::UPSERT_BATCH_SIZE);
            }
        } else {
            $execution = $executions[0];
            Span::add('function.id', $execution->getAttribute('resourceId', ''));
            Span::add('execution.id', $execution->getId());
            Span::add('deployment.id', $execution->getAttribute('deploymentId', ''));
            Span::add('resource.type', $execution->getAttribute('resourceType', ''));

            if ($this->isPending($execution)) {
                $this->create($dbForProject, $execution);
            } else {
                $dbForProject->upsertDocument('executions', $execution);
            }
        }
    }

    private function isPending(Document $execution): bool
    {
        return \in_array($execution->getAttribute('status', ''), self::PENDING_STATUSES, true);
    }

    private function create(Database $dbForProject, Document $execution): void
    {
        try {
            $dbForProject->createDocument('executions', $execution);
        } catch (Duplicate) {
            // A terminal write or a redelivery already created the document.
        }
    }
}
