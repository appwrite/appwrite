<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\Databases\Http\Databases\Action as DatabasesAction;
use Appwrite\Utopia\Database\Validator\CustomId;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

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
     * Emit `documents.[id].create` events for child rows that were created
     * implicitly by utopia-php/database through a relationship attribute.
     *
     * Why this exists:
     *
     * When a parent document carries nested relationship payloads (one-to-many,
     * many-to-one, ...), utopia-php/database resolves and creates each child
     * inside its own `silent()` block (see Database::createDocument). That
     * silent block suppresses the low-level `EVENT_DOCUMENT_CREATE` trigger
     * for every child row, and the HTTP create action only enqueues a single
     * event for the root document. The end-result is that any Appwrite
     * Function, Webhook, or Realtime subscriber listening to
     * `databases.*.collections.{B}.documents.*.create` is never invoked for
     * those nested children, even though the rows clearly exist in B.
     *
     * This helper replays the missing event for each newly-created child,
     * mirroring exactly what `triggerBulk` does for bulk creates so behaviour
     * is consistent regardless of how the row was inserted.
     *
     * Caller contract:
     *  - `$nestedCreates` is a list of `['collection' => Document, 'documentId' => string]`.
     *  - `$dbForDatabases` MUST be the same connection that ran the parent
     *    `createDocuments()` (matters for documents-db/vectors-db adapters).
     *  - On return the main `$queueForEvents` is reset (params/event/payload
     *    cleared, context preserved). The caller is expected to re-populate
     *    it with the root document's event right after.
     *
     * @param array<int, array{collection: Document, documentId: string}> $nestedCreates
     */
    protected function triggerRelationshipCreates(
        array $nestedCreates,
        Document $database,
        Database $dbForDatabases,
        Database $dbForProject,
        Event $queueForEvents,
        Event $queueForRealtime,
        Event $queueForFunctions,
        Event $queueForWebhooks,
        EventProcessor $eventProcessor,
        Authorization $authorization,
    ): void {
        if (empty($nestedCreates)) {
            return;
        }

        $project = $queueForEvents->getProject();
        $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
        $webhooksEvents = $eventProcessor->getWebhooksEvents($project);
        $collectionsCache = [];
        $isConsole = $project->getId() === 'console';

        foreach ($nestedCreates as $entry) {
            /** @var Document $relatedCollection */
            $relatedCollection = $entry['collection'];
            $documentId = $entry['documentId'];

            $childDoc = $authorization->skip(
                fn () => $dbForDatabases->getDocument(
                    'database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence(),
                    $documentId
                )
            );

            // Child may have been deduplicated or skipped by the adapter (e.g.
            // when the relationship value referenced an existing row). Safe to skip.
            if ($childDoc->isEmpty()) {
                continue;
            }

            // Attach $databaseId / $collectionId / $tableId metadata to the
            // payload so subscribers see the same shape as a direct create.
            $this->processDocument(
                database: $database,
                collection: $relatedCollection,
                document: $childDoc,
                dbForProject: $dbForProject,
                collectionsCache: $collectionsCache,
                authorization: $authorization,
            );

            $queueForEvents
                ->setEvent('databases.[databaseId].collections.[collectionId].documents.[documentId].create')
                ->setParam('databaseId', $database->getId())
                ->setContext('database', $database)
                ->setParam('collectionId', $relatedCollection->getId())
                ->setParam('tableId', $relatedCollection->getId())
                ->setContext($this->getCollectionsEventsContext(), $relatedCollection)
                ->setParam('documentId', $childDoc->getId())
                ->setParam('rowId', $childDoc->getId())
                ->setPayload($childDoc->getArrayCopy());

            // Realtime is suppressed for the console project to match api.php shutdown logic.
            if (!$isConsole) {
                $queueForRealtime->from($queueForEvents)->trigger();
            }

            $generatedEvents = Event::generateEvents(
                $queueForEvents->getEvent(),
                $queueForEvents->getParams()
            );

            if (!empty($functionsEvents)) {
                foreach ($generatedEvents as $eventName) {
                    if (isset($functionsEvents[$eventName])) {
                        $queueForFunctions->from($queueForEvents)->trigger();
                        break;
                    }
                }
            }

            if (!empty($webhooksEvents)) {
                foreach ($generatedEvents as $eventName) {
                    if (isset($webhooksEvents[$eventName])) {
                        $queueForWebhooks->from($queueForEvents)->trigger();
                        break;
                    }
                }
            }
        }

        // Clear params/event/payload so the caller can set the root event
        // cleanly. Context (project, user, database, etc.) is preserved by reset().
        $queueForEvents->reset();
        $queueForRealtime->reset();
        $queueForFunctions->reset();
        $queueForWebhooks->reset();
    }
}
