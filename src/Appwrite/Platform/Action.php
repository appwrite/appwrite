<?php

namespace Appwrite\Platform;

use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Platform\Action as UtopiaAction;

class Action extends UtopiaAction
{
    /**
     * Log Error Callback
     *
     * @var callable
     */
    protected mixed $logError;

    /**
     * Foreach Document
     * Call provided callback for each document in the collection
     *
     * @param string $projectId
     * @param string $collection
     * @param array $queries
     * @param callable $callback
     *
     * @return void
     */
    protected function foreachDocument(Database $database, string $collection, array $queries = [], callable $callback = null, int $limit = 1000, bool $concurrent = false): void
    {
        $results = [];
        $sum = $limit;
        $latestDocument = null;

        while ($sum === $limit) {
            $newQueries = $queries;
            try {
                if ($latestDocument !== null) {
                    array_unshift($newQueries, Query::cursorAfter($latestDocument));
                }
                $newQueries[] = Query::limit($limit);
                $database->disableValidation();
                $results = $database->find($collection, $newQueries);
                $database->enableValidation();
            } catch (\Exception $e) {
                if (!empty($this->logError)) {
                    call_user_func_array($this->logError, [$e, "CLI", "fetch_documents_namespace_{$database->getNamespace()}_collection{$collection}"]);
                }
            }

            if (empty($results)) {
                return;
            }

            $sum = count($results);

            if ($concurrent) {
                $callables = [];
                $errors = [];

                foreach ($results as $document) {
                    if (is_callable($callback)) {
                        $callables[] = Co\go(function () use ($document, $callback, &$errors) {
                            try {
                                $callback($document);
                            } catch (\Throwable $error) {
                                $errors[] = $error;
                            }
                        });
                    }
                }

                Co::join($callables);

                if (!empty($errors)) {
                    throw new \Error("Errors found in concurrent foreachDocument: " . \json_encode($errors));
                }
            } else {
                foreach ($results as $document) {
                    if (is_callable($callback)) {
                        $callback($document);
                    }
                }
            }

            $latestDocument = $results[array_key_last($results)];
        }
    }
}
