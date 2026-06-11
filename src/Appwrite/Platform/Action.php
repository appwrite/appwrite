<?php

namespace Appwrite\Platform;

use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action as UtopiaAction;

class Action extends UtopiaAction
{
    /**
     * Log Error Callback
     *
     * @var callable
     */
    protected mixed $logError;

    protected array $filters = [
        'subQueryKeys', 'subQueryWebhooks', 'subQueryPlatforms', 'subQueryBlocks', 'subQueryDevKeys', // Project
        'subQueryAuthenticators', 'subQuerySessions', 'subQueryTokens', 'subQueryChallenges', 'subQueryMemberships', 'subQueryTargets', 'subQueryTopicTargets',// Users
        'subQueryVariables', 'subQueryProjectVariables' // Sites / Functions
    ];

    /**
     * Attributes to remove from relationship path documents per API
     * Default is empty - APIs should set their specific attributes
     *
     * @var array
     */
    protected array $removableAttributes = [];

    /**
     * Foreach Document
     * Call provided callback for each document in the collection
     *
     * @param Database $database
     * @param string $collection
     * @param array $queries
     * @param callable $callback
     *
     * @return void
     */
    protected function foreachDocument(Database $database, string $collection, array $queries = [], ?callable $callback = null, int $limit = 1000, bool $concurrent = false): void
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

    /**
     * Update the project document from a locked read of the current row.
     *
     * Project configuration lives in map attributes (services, auths, smtp,
     * apis, oAuthProviders, templates) that endpoints merge a single key into.
     * Computing that merge from the request-scoped project resource loses
     * concurrent writes: the resource is a cached read taken at request start,
     * and writing the merged map back replaces the whole attribute. The
     * changes callback receives the current row, read under a row lock inside
     * a transaction, so the merge is atomic with the write.
     *
     * @param callable(Document): array<string, mixed> $changes
     */
    protected function updateProject(
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        callable $changes
    ): Document {
        return $dbForPlatform->withTransaction(fn () => $authorization->skip(function () use ($dbForPlatform, $project, $changes) {
            $current = $dbForPlatform->getDocument('projects', $project->getId(), forUpdate: true);

            return $dbForPlatform->updateDocument('projects', $project->getId(), new Document($changes($current)));
        }));
    }

    public function disableSubqueries(array $filters = []): void
    {
        if (empty($filters)) {
            $filters = $this->filters;
        }

        foreach ($filters as $filter) {
            Database::addFilter(
                $filter,
                function (mixed $value) {
                    return;
                },
                function (mixed $value, Document $document, Database $database) {
                    return [];
                }
            );
        }
    }

    /**
     * Dump Log Message
     *
     * Logs messages to console with timestamp, method context, and project details.
     * Supports multiple log types: success, error, log, warning, and info (default).
     *
     * @param string $method The calling method name
     * @param string $log The log message
     * @param string $type The log type (success, error, log, warning, info)
     * @param Document|null $project The project document for context
     * @param string $collectionId The collection identifier
     * @return void
     */
    public function dump(string $method, string $log, string $type = 'info', ?Document $project = null, string $collectionId = ''): void
    {
        if (empty($project)) {
            $project = new Document([]);
        }
        switch ($type) {
            case 'success':
                Console::success("[" . DateTime::now() . "] " . $method . ' ' . $type . ' ' . $project->getSequence() . ' ' . $project->getId() . ' ' . $collectionId . ' ' . $log);
                break;
            case 'error':
                Console::error("[" . DateTime::now() . "] " . $method . ' ' . $type . ' ' . $project->getSequence() . ' ' . $project->getId() . ' ' . $collectionId . ' ' . $log);
                break;
            case 'log':
                Console::log("[" . DateTime::now() . "] " . $method . ' ' . $type . ' ' . $project->getSequence() . ' ' . $project->getId() . ' ' . $collectionId . ' ' . $log);
                break;
            case 'warning':
                Console::warning("[" . DateTime::now() . "] " . $method . ' ' . $type . ' ' . $project->getSequence() . ' ' . $project->getId() . ' ' . $collectionId . ' ' . $log);
                break;
            default:
                Console::info("[" . DateTime::now() . "] " . $method . ' ' . $type . ' ' . $project->getSequence() . ' ' . $project->getId() . ' ' . $collectionId . ' ' . $log);
        }
    }
}
