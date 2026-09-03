<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Appwrite\Event\Message\ExecutionCancelled as ExecutionCancelledMessage;
use Appwrite\Event\Message\Executions as ExecutionsMessage;
use Appwrite\Execution\Store;
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
            ->inject('executionStore')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Database $dbForProject,
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

            $created = [];
            foreach ($pending as $execution) {
                $created[] = $this->create($dbForProject, $execution);
            }
            if ($created !== []) {
                $executionStore->upsertMany($executionMessage->project->getId(), $created);
            }

            if (!empty($final)) {
                $stored = [];
                $dbForProject->upsertDocuments(
                    'executions',
                    $final,
                    self::UPSERT_BATCH_SIZE,
                    function (Document $execution) use (&$stored): void {
                        $stored[$execution->getId()] = $execution;
                    }
                );

                // An unchanged redelivery does not invoke upsertDocuments' callback.
                // Read those rows back so a retry after a ClickHouse failure still heals
                // the mirror and preserves the database-assigned sequence.
                foreach ($final as $execution) {
                    if (!isset($stored[$execution->getId()])) {
                        $stored[$execution->getId()] = $dbForProject->getDocument('executions', $execution->getId());
                    }
                }
                $executionStore->upsertMany($executionMessage->project->getId(), \array_values($stored));
            }
        } else {
            $execution = $executions[0];
            Span::add('function.id', $execution->getAttribute('resourceId', ''));
            Span::add('execution.id', $execution->getId());
            Span::add('deployment.id', $execution->getAttribute('deploymentId', ''));
            Span::add('resource.type', $execution->getAttribute('resourceType', ''));

            if ($this->isPending($execution)) {
                $stored = $this->create($dbForProject, $execution);
                $executionStore->upsert($executionMessage->project->getId(), $stored);
            } else {
                $stored = null;
                $dbForProject->upsertDocuments(
                    'executions',
                    [$execution],
                    self::UPSERT_BATCH_SIZE,
                    function (Document $execution) use (&$stored): void {
                        $stored = $execution;
                    }
                );

                // An unchanged redelivery does not invoke upsertDocuments' callback.
                $stored ??= $dbForProject->getDocument('executions', $execution->getId());
                $executionStore->upsert($executionMessage->project->getId(), $stored);
            }
        }
    }

    private function isPending(Document $execution): bool
    {
        return \in_array($execution->getAttribute('status', ''), self::PENDING_STATUSES, true);
    }

    private function create(Database $dbForProject, Document $execution): Document
    {
        try {
            return $dbForProject->createDocument('executions', $execution);
        } catch (Duplicate) {
            // A terminal write or a redelivery already created the document.
            return $dbForProject->getDocument('executions', $execution->getId());
        }
    }
}
