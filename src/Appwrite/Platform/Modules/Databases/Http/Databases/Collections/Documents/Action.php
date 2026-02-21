<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Action as DatabasesAction;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

abstract class Action extends DatabasesAction
{
    /**
     * @var string|null The current context (either 'row' or 'document')
     */
    private ?string $context = DOCUMENTS;

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    public function setHttpPath(string $path): DatabasesAction
    {
        if (str_contains($path, '/tablesdb/')) {
            $this->context = ROWS;
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

        return parent::setHttpPath($path);
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
                        operations: $operations,
                        authorization: $authorization
                    );
                }
            }

            if (\is_array($related)) {
                $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
            } elseif (empty($relations)) {
                $document->setAttribute($relationship->getAttribute('key'), null);
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
     * @param Event $queueForFunctions
     * @param Event $queueForWebhooks
     * @return void
     */
    protected function triggerBulk(
        string $event,
        Document $database,
        Document $collection,
        array $documents,
        Event $queueForEvents,
        Event $queueForRealtime,
        Event $queueForFunctions,
        Event $queueForWebhooks
    ): void {
        $queueForEvents
            ->setEvent($event)
            ->setParam('databaseId', $database->getId())
            ->setContext('database', $database)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection);

        foreach ($documents as $document) {
            $queueForEvents
                ->setParam('documentId', $document->getId())
                ->setParam('rowId', $document->getId())
                ->setPayload($document->getArrayCopy());

            $queueForRealtime
                ->from($queueForEvents)
                ->trigger();

            $queueForFunctions
                ->from($queueForEvents)
                ->trigger();

            $queueForWebhooks
                ->from($queueForEvents)
                ->trigger();
        }

        $queueForEvents->reset();
        $queueForRealtime->reset();
        $queueForFunctions->reset();
        $queueForWebhooks->reset();
    }
}
