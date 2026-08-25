<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Execution\Store;
use Appwrite\Platform\Action;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;

class ExecutionsBackfill extends Action
{
    private const int DEFAULT_BATCH_SIZE = 1000;

    public static function getName(): string
    {
        return 'executions-backfill';
    }

    public function __construct()
    {
        $this
            ->desc('Backfill function and site executions into ClickHouse')
            ->param('projectId', '', new UID(), 'Only backfill this project.', true)
            ->param('batchSize', self::DEFAULT_BATCH_SIZE, new Integer(true), 'Executions to insert per batch.', true)
            ->param('restart', false, new Boolean(true), 'Restart completed project checkpoints.', true)
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('executionStore')
            ->callback($this->action(...));
    }

    /** @param callable(Document): Database $getProjectDB */
    public function action(
        string $projectId,
        int $batchSize,
        bool $restart,
        Database $dbForPlatform,
        callable $getProjectDB,
        Store $executionStore,
    ): void {
        if (!$executionStore->isEnabled()) {
            throw new \RuntimeException('Execution ClickHouse persistence is disabled');
        }

        if ($batchSize < 1 || $batchSize > Database::INSERT_BATCH_SIZE) {
            throw new \InvalidArgumentException('Batch size must be between 1 and ' . Database::INSERT_BATCH_SIZE);
        }

        $executionStore->setup();

        if ($projectId !== '') {
            $project = $dbForPlatform->getAuthorization()->skip(
                fn () => $dbForPlatform->getDocument('projects', $projectId)
            );
            if ($project->isEmpty()) {
                throw new \RuntimeException("Project '{$projectId}' not found");
            }

            $this->backfill($project, $getProjectDB($project), $executionStore, $batchSize, $restart);
            return;
        }

        $dbForPlatform->getAuthorization()->skip(function () use ($dbForPlatform, $getProjectDB, $executionStore, $batchSize, $restart): void {
            $dbForPlatform->foreach('projects', function (Document $project) use ($getProjectDB, $executionStore, $batchSize, $restart): void {
                $this->backfill($project, $getProjectDB($project), $executionStore, $batchSize, $restart);
            }, [Query::orderAsc('$sequence')]);
        });
    }

    private function backfill(
        Document $project,
        Database $dbForProject,
        Store $executionStore,
        int $batchSize,
        bool $restart,
    ): void {
        $projectId = $project->getId();
        if ($restart) {
            $executionStore->saveBackfillCheckpoint($projectId, '', false);
        }
        $checkpoint = $executionStore->getBackfillCheckpoint($projectId);

        if ($checkpoint['completed']) {
            Console::log("Executions for project '{$projectId}' are already backfilled");
            return;
        }

        $cursor = new Document();
        if ($checkpoint['executionId'] !== '') {
            $cursor = $dbForProject->getAuthorization()->skip(
                fn () => $dbForProject->getDocument('executions', $checkpoint['executionId'])
            );
            if ($cursor->isEmpty()) {
                Console::warning("Execution checkpoint for project '{$projectId}' no longer exists; restarting the project");
            }
        }

        $copied = 0;
        while (true) {
            $queries = [
                Query::orderAsc('$sequence'),
                Query::limit($batchSize),
            ];
            if (!$cursor->isEmpty()) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $executions = $dbForProject->getAuthorization()->skip(
                fn () => $dbForProject->find('executions', $queries)
            );
            if ($executions === []) {
                $executionStore->saveBackfillCheckpoint($projectId, $cursor->getId(), true);
                break;
            }

            $executionStore->upsertMany($projectId, $executions);
            $cursor = $executions[\array_key_last($executions)];
            $copied += \count($executions);
            $completed = \count($executions) < $batchSize;
            $executionStore->saveBackfillCheckpoint($projectId, $cursor->getId(), $completed);

            Console::log("Backfilled {$copied} executions for project '{$projectId}'");
            if ($completed) {
                break;
            }
        }

        Console::success("Execution backfill completed for project '{$projectId}'");
    }
}
