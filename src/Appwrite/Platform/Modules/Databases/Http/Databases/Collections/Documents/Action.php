<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\Databases\Http\Databases\Action as DatabasesAction;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;

abstract class Action extends DatabasesAction
{
    /**
     * @var string The current context (either 'row' or 'document')
     */
    private string $context = DOCUMENTS;
    private string $databaseType = DATABASE_TYPE_LEGACY;

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    public function setHttpPath(string $path): self
    {
        if (str_contains($path, '/tablesdb/')) {
            $this->context = ROWS;
        } elseif (str_contains($path, '/documentsdb/')) {
            $this->databaseType = DATABASE_TYPE_DOCUMENTSDB;
        } elseif (str_contains($path, '/vectorsdb/')) {
            $this->databaseType = DATABASE_TYPE_VECTORSDB;
        }

        $contextId = '$' . $this->getCollectionsEventsContext() . 'Id';
        $this->removableAttributes = [
            '*' => [
                '$sequence',
                '$databaseId',
                $contextId,
            ],
            'privileged' => [
                '$createdAt',
                '$updatedAt',
            ],
        ];

        parent::setHttpPath($path);
        return $this;
    }

    protected function getDatabasesOperationReadMetric(): string
    {
        if ($this->databaseType === DATABASE_TYPE_LEGACY || $this->databaseType === DATABASE_TYPE_TABLESDB) {
            return METRIC_DATABASES_OPERATIONS_READS;
        }
        return $this->databaseType.'.'.METRIC_DATABASES_OPERATIONS_READS;
    }

    protected function getDatabasesIdOperationReadMetric(): string
    {
        if ($this->databaseType === DATABASE_TYPE_LEGACY || $this->databaseType === DATABASE_TYPE_TABLESDB) {
            return METRIC_DATABASE_ID_OPERATIONS_READS;
        }
        return $this->databaseType.'.'.METRIC_DATABASE_ID_OPERATIONS_READS;
    }

    protected function getDatabasesOperationWriteMetric(): string
    {
        if ($this->databaseType === DATABASE_TYPE_LEGACY || $this->databaseType === DATABASE_TYPE_TABLESDB) {
            return METRIC_DATABASES_OPERATIONS_WRITES;
        }
        return $this->databaseType.'.'.METRIC_DATABASES_OPERATIONS_WRITES;

    }

    protected function getDatabasesIdOperationWriteMetric(): string
    {
        if ($this->databaseType === DATABASE_TYPE_LEGACY || $this->databaseType === DATABASE_TYPE_TABLESDB) {
            return METRIC_DATABASE_ID_OPERATIONS_WRITES;
        }
        return $this->databaseType.'.'.METRIC_DATABASE_ID_OPERATIONS_WRITES;
    }

    /**
     * Get the plural of the given name.
     *
     * Used for endpoints with multiple sdk methods.
     */
    protected function getBulkActionName(string $name): string
    {
        return "{$name}s";
    }

    /**
     * Get the current context.
     */
    protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Returns true if current context is Collections API.
     */
    protected function isCollectionsAPI(): bool
    {
        // rows in tables api context
        // documents in collections api context
        return $this->getContext() === DOCUMENTS;
    }

    /**
     * Get the SDK group name for the current action.
     *
     * Can be used for XList operations as well!
     */
    protected function getSDKGroup(): string
    {
        return $this->isCollectionsAPI() ? 'documents' : 'rows';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    protected function getSDKNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'databases' : 'tablesDB';
    }

    /**
     * Get the correct attribute/column structure context for errors.
     */
    protected function getStructureContext(): string
    {
        return $this->isCollectionsAPI() ? 'attributes' : 'columns';
    }

    /**
     * Get the appropriate parent level not found exception.
     */
    protected function getParentNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the appropriate attribute/column not found exception.
     */
    protected function getStructureNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_NOT_FOUND
            : Exception::COLUMN_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_NOT_FOUND
            : Exception::ROW_NOT_FOUND;
    }

    /**
     * Get the appropriate already exists exception.
     */
    protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_ALREADY_EXISTS
            : Exception::ROW_ALREADY_EXISTS;
    }

    /**
     * Get the appropriate conflict exception.
     */
    protected function getConflictException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_UPDATE_CONFLICT
            : Exception::ROW_UPDATE_CONFLICT;
    }

    /**
     * Get the appropriate delete restricted exception.
     */
    protected function getRestrictedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_DELETE_RESTRICTED
            : Exception::ROW_DELETE_RESTRICTED;
    }

    /**
     * Get the correct invalid structure message.
     */
    protected function getStructureException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_INVALID_STRUCTURE
            : Exception::ROW_INVALID_STRUCTURE;
    }

    /**
     * Get the appropriate missing data exception.
     */
    protected function getMissingDataException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_MISSING_DATA
            : Exception::ROW_MISSING_DATA;
    }

    /**
     * Get the exception to throw when the resource limit is exceeded.
     */
    protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_LIMIT_EXCEEDED
            : Exception::COLUMN_LIMIT_EXCEEDED;
    }

    /**
     * Get the appropriate missing payload exception.
     */
    protected function getMissingPayloadException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_MISSING_PAYLOAD
            : Exception::ROW_MISSING_PAYLOAD;
    }

    /**
     * Get the correct collections context for Events queue.
     */
    protected function getCollectionsEventsContext(): string
    {
        return $this->isCollectionsAPI() ? 'collection' : 'table';
    }

    /**
     * Get the correct attribute/column key for increment/decrement operations.
     */
    protected function getAttributeKey(): string
    {
        return $this->isCollectionsAPI() ? 'attribute' : 'column';
    }

    /**
     * Get the key used in ID parameters (e.g., 'collectionId' or 'tableId').
     */
    protected function getGroupId(): string
    {
        return $this->getCollectionsEventsContext() . 'Id';
    }

    /**
     * Get the resource ID key for the current action.
     */
    protected function getResourceId(): string
    {
        $resource = $this->isCollectionsAPI() ? 'document' : 'row';
        return $resource . 'Id';
    }

    /**
     * Remove configured removable attributes from a document.
     * Used for relationship path handling to remove API-specific attributes.
     */
    protected function removeReadonlyAttributes(
        Document|array $document,
        bool $privileged = false,
    ): Document|array {
        foreach ($this->removableAttributes['*'] as $attribute) {
            unset($document[$attribute]);
        }
        if (!$privileged) {
            foreach ($this->removableAttributes['privileged'] ?? [] as $attribute) {
                unset($document[$attribute]);
            }
        }
        return $document;
    }

    /**
     * Validate relationship values.
     * Handles Document objects, ID strings, and associative arrays.
     */
    protected function validateRelationship(mixed $relation): void
    {
        $relationId = null;

        if ($relation instanceof Document) {
            $relationId = $relation->getId();
        } elseif (\is_string($relation)) {
            $relationId = $relation;
        } elseif (\is_array($relation) && !\array_is_list($relation)) {
            $relationId = $relation['$id'] ?? null;
        } else {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, 'Relationship value must be an object, document ID string, or associative array');
        }

        if ($relationId !== null) {
            if (!\is_string($relationId)) {
                throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, 'Relationship $id must be a string');
            }
            $validator = new CustomId();
            if (!$validator->isValid($relationId)) {
                throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $validator->getDescription());
            }
        }
    }

    /**
     * Resolves relationships in a document and attaches metadata.
     */
    protected function processDocument(
        /* database */
        Document $database,
        Document $collection,
        Document $document,
        Database $dbForProject,
        /* options */
        array &$collectionsCache,
        Authorization $authorization,
        ?int &$operations = null,
        int $depth = 0,
    ): bool {
        if ($operations !== null && $document->isEmpty()) {
            return false;
        }

        if ($operations !== null) {
            $operations++;
        }

        $collectionId = $collection->getId();
        $document->removeAttribute('$collection');
        $document->setAttribute('$databaseId', $database->getId());
        $document->setAttribute('$' . $this->getCollectionsEventsContext() . 'Id', $collectionId);

        // Stop processing relationships if max depth reached
        if ($depth >= Database::RELATION_MAX_DEPTH) {
            return true;
        }

        $relationships = $collectionsCache[$collectionId] ??= \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attr) => $attr->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        foreach ($relationships as $relationship) {
            $key = $relationship->getAttribute('key');
            $related = $document->getAttribute($key);

            if (empty($related)) {
                if (\in_array(\gettype($related), ['array', 'object']) && $operations !== null) {
                    $operations++;
                }
                continue;
            }

            $relations = \is_array($related) ? $related : [$related];
            $relatedCollectionId = $relationship->getAttribute('relatedCollection');

            if (!isset($collectionsCache[$relatedCollectionId])) {
                $relatedCollectionDoc = $authorization->skip(
                    fn () => $dbForProject->getDocument(
                        'database_' . $database->getSequence(),
                        $relatedCollectionId
                    )
                );

                $collectionsCache[$relatedCollectionId] = \array_filter(
                    $relatedCollectionDoc->getAttribute('attributes', []),
                    fn ($attr) => $attr->getAttribute('type') === Database::VAR_RELATIONSHIP
                );
            }

            foreach ($relations as $relation) {
                if ($relation instanceof Document) {
                    $relatedCollection = new Document([
                        '$id' => $relatedCollectionId,
                        'attributes' => $collectionsCache[$relatedCollectionId],
                    ]);

                    $this->processDocument(
                        database: $database,
                        collection: $relatedCollection,
                        document: $relation,
                        dbForProject: $dbForProject,
                        collectionsCache: $collectionsCache,
                        authorization: $authorization,
                        operations: $operations,
                        depth: $depth + 1
                    );
                }
            }

            if (\is_array($related)) {
                $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
            }
        }

        return true;
    }

    /**
     * For triggering different queues for each document for a bulk documents
     * @param string $event
     * @param Document $database
     * @param Document $collection
     * @param Document[] $documents
     * @param Event $queueForEvents
     * @param Event $queueForRealtime
     * @param FunctionPublisher $publisherForFunctions
     * @param Event $queueForWebhooks
     * @param Database $dbForProject
     * @param EventProcessor $eventProcessor
     * @return void
     */
    protected function triggerBulk(
        string $event,
        Document $database,
        Document $collection,
        array $documents,
        Event $queueForEvents,
        Event $queueForRealtime,
        FunctionPublisher $publisherForFunctions,
        Event $queueForWebhooks,
        Database $dbForProject,
        EventProcessor $eventProcessor
    ): void {
        $queueForEvents
            ->setEvent($event)
            ->setParam('databaseId', $database->getId())
            ->setContext('database', $database)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection);

        // Get project and function events (cached)
        $project = $queueForEvents->getProject();
        $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
        $webhooksEvents = $eventProcessor->getWebhooksEvents($project);

        foreach ($documents as $document) {
            $queueForEvents
                ->setParam('documentId', $document->getId())
                ->setParam('rowId', $document->getId())
                ->setPayload($document->getArrayCopy());

            $queueForRealtime
                ->from($queueForEvents)
                ->trigger();

            // Generate events for this document operation
            $generatedEvents = Event::generateEvents(
                $queueForEvents->getEvent(),
                $queueForEvents->getParams()
            );


            if (!empty($functionsEvents)) {
                foreach ($generatedEvents as $event) {
                    if (isset($functionsEvents[$event])) {
                        $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                            event: $queueForEvents->getEvent(),
                            params: $queueForEvents->getParams(),
                            project: $queueForEvents->getProject(),
                            user: $queueForEvents->getUser(),
                            userId: $queueForEvents->getUserId(),
                            payload: $queueForEvents->getPayload(),
                            platform: $queueForEvents->getPlatform(),
                        ));
                        break;
                    }
                }
            }

            if (!empty($webhooksEvents)) {
                foreach ($generatedEvents as $event) {
                    if (isset($webhooksEvents[$event])) {
                        $queueForWebhooks
                            ->from($queueForEvents)
                            ->trigger();
                        break;
                    }
                }
            }
        }

        $queueForEvents->reset();
        $queueForRealtime->reset();
        $queueForWebhooks->reset();
    }

    /**
     * Shared setup for any list-style read on the documents/rows API surface.
     *
     * Both listRows/listDocuments and explainRows/explainDocuments need the
     * same auth checks, database+collection lookup, query parse, cursor
     * resolution, and find-closure construction. Centralising it here is the
     * single source of truth so the explain endpoint stays byte-identical to
     * the real read it's explaining.
     *
     * Returned bundle:
     *   'database'           Document  the user-facing database doc
     *   'collection'         Document  the user-facing collection doc
     *   'dbForDatabases'     Database  the per-database adapter the read runs against
     *   'queries'            Query[]   parsed Query objects (cursor value resolved)
     *   'collectionTableId'  string    physical table id (`database_X_collection_Y`)
     *   'hasSelects'         bool      whether the queries include any select
     *   'find'               callable  closure that runs the find — wraps in skipRelationships when there are no related selects
     *
     * @param  array<string>  $queries  raw stringified queries from the HTTP request
     * @return array<string, mixed>
     */
    protected function prepareListContext(
        string $databaseId,
        string $collectionId,
        array $queries,
        Database $dbForProject,
        User $user,
        callable $getDatabasesDB,
        Authorization $authorization,
    ): array {
        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (! $database->getAttribute('enabled', false) && ! $isAPIKey && ! $isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (! $collection->getAttribute('enabled', false) && ! $isAPIKey && ! $isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $dbForDatabases = $getDatabasesDB($database);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (! $validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $documentId = $cursor->getValue();

            try {
                $cursorDocument = $authorization->skip(fn () => $dbForDatabases->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId));
            } catch (NotFoundException) {
                // The collection metadata document exists but the backing
                // store has no table for it. Treat as collection not-found
                // so the caller sees a 404 instead of a 500.
                throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
            }

            if ($cursorDocument->isEmpty()) {
                $type = ucfirst($this->getContext());
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "$type '{$documentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
        $hasSelects = ! empty(Query::groupByType($queries)['selections']);

        // When there are no select queries, relationship loading is skipped on
        // the underlying find() to avoid pulling related documents the caller
        // did not ask for.
        $find = $hasSelects
            ? fn () => $dbForDatabases->find($collectionTableId, $queries)
            : fn () => $dbForDatabases->skipRelationships(fn () => $dbForDatabases->find($collectionTableId, $queries));

        return [
            'database' => $database,
            'collection' => $collection,
            'dbForDatabases' => $dbForDatabases,
            'queries' => $queries,
            'collectionTableId' => $collectionTableId,
            'hasSelects' => $hasSelects,
            'find' => $find,
        ];
    }
}
