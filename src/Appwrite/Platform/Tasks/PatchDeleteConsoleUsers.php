<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Delete;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;

class PatchDeleteConsoleUsers extends Action
{
    public static function getName(): string
    {
        return 'patch-delete-console-users';
    }

    public function __construct()
    {
        $this
            ->desc('Delete console users patch')
            ->inject('dbForConsole')
            ->inject('queueForDeletes')
            ->callback(fn ($dbForConsole, $queueForDeletes) => $this->action($dbForConsole, $queueForDeletes));
    }

    public function action(Database $dbForConsole, Delete $queueForDeletes): void
    {
        Console::info("Starting the patch");

        $startTime = microtime(true);
        $query = [Query::equal('status', [false])];
        $this->foreachDocument($dbForConsole, 'users', $query, function ($user) use ($dbForConsole, $queueForDeletes) {
            $clone = clone $user;
            try {
                $dbForConsole->deleteDocument('users', $user->getId());
                $queueForDeletes
                    ->setProject(new Document([
                        '$id' => ID::custom('console'),
                        '$internalId' => ID::custom('console')
                    ]))
                    ->setType(DELETE_TYPE_DOCUMENT)
                    ->setDocument($clone)
                    ->trigger();
            } catch (\Throwable $th) {
                Console::error("Unexpected error occurred with User ID {$clone->getId()}");
                Console::error('[Error] Type: ' . get_class($th));
                Console::error('[Error] Message: ' . $th->getMessage());
                Console::error('[Error] File: ' . $th->getFile());
                Console::error('[Error] Line: ' . $th->getLine());
            }
        });


        $endTime = microtime(true);
        $timeTaken = $endTime - $startTime;

        $hours = (int)($timeTaken / 3600);
        $timeTaken -= $hours * 3600;
        $minutes = (int)($timeTaken / 60);
        $timeTaken -= $minutes * 60;
        $seconds = (int)$timeTaken;
        $milliseconds = ($timeTaken - $seconds) * 1000;
        Console::info("Delete console users patch completed in $hours h, $minutes m, $seconds s, $milliseconds mis ( total $timeTaken milliseconds)");
    }

    protected function foreachDocument(Database $database, string $collection, array $queries = [], callable $callback = null): void
    {
        $limit = 1000;
        $results = [];
        $sum = $limit;
        $latestDocument = null;

        while ($sum === $limit) {
            $newQueries = $queries;

            if ($latestDocument != null) {
                array_unshift($newQueries, Query::cursorAfter($latestDocument));
            }
            $newQueries[] = Query::limit($limit);
            $results = $database->find($collection, $newQueries);

            if (empty($results)) {
                return;
            }

            $sum = count($results);

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }
            }
            $latestDocument = $results[array_key_last($results)];
        }
    }
}
