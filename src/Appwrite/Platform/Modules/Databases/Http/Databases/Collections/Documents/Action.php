<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action as UtopiaAction;

abstract class Action extends UtopiaAction
{
    /**
     * @var string|null The current context (either 'row' or 'document')
     */
    private ?string $context = DOCUMENTS;

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    public function setHttpPath(string $path): UtopiaAction
    {
        if (str_contains($path, '/:databaseId/grids/tables')) {
            $this->context = ROWS;
        }

        return parent::setHttpPath($path);
    }

    /**
     * Get the plural of the given name.
     *
     * Used for endpoints with multiple sdk methods.
     */
    final protected function getBulkActionName(string $name): string
    {
        return "{$name}s";
    }

    /**
     * Get the current context.
     */
    final protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Returns true if current context is Collections API.
     */
    final protected function isCollectionsAPI(): bool
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
    final protected function getSdkGroup(): string
    {
        return $this->isCollectionsAPI() ? 'documents' : 'rows';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    final protected function getSdkNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'databases' : 'grids';
    }

    /**
     * Get the correct attribute/column structure context for errors.
     */
    final protected function getStructureContext(): string
    {
        return $this->isCollectionsAPI() ? 'attributes' : 'columns';
    }

    /**
     * Get the appropriate parent level not found exception.
     */
    final protected function getParentNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the appropriate attribute/column not found exception.
     */
    final protected function getStructureNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_NOT_FOUND
            : Exception::COLUMN_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    final protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_NOT_FOUND
            : Exception::ROW_NOT_FOUND;
    }

    /**
     * Get the appropriate already exists exception.
     */
    final protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_ALREADY_EXISTS
            : Exception::ROW_ALREADY_EXISTS;
    }

    /**
     * Get the appropriate conflict exception.
     */
    final protected function getConflictException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_UPDATE_CONFLICT
            : Exception::ROW_UPDATE_CONFLICT;
    }

    /**
     * Get the appropriate delete restricted exception.
     */
    final protected function getRestrictedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_DELETE_RESTRICTED
            : Exception::ROW_DELETE_RESTRICTED;
    }

    /**
     * Get the correct invalid structure message.
     */
    final protected function getInvalidStructureException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_INVALID_STRUCTURE
            : Exception::ROW_INVALID_STRUCTURE;
    }

    /**
     * Get the appropriate missing data exception.
     */
    final protected function getMissingDataException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_MISSING_DATA
            : Exception::ROW_MISSING_DATA;
    }

    /**
     * Get the exception to throw when the resource limit is exceeded.
     */
    final protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_LIMIT_EXCEEDED
            : Exception::COLUMN_LIMIT_EXCEEDED;
    }

    /**
     * Get the appropriate missing payload exception.
     */
    final protected function getMissingPayloadException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_MISSING_PAYLOAD
            : Exception::ROW_MISSING_PAYLOAD;
    }

    /**
     * Get the correct collections context for Events queue.
     */
    final protected function getCollectionsEventsContext(): string
    {
        return $this->isCollectionsAPI() ? 'collection' : 'table';
    }

    /**
     * Resolves relationships in a document and attaches metadata.
     */
    final protected function processDocument(
        /* database */
        Document $database,
        Document $collection,
        Document $document,
        Database $dbForProject,

        /* options */
        array &$collectionsCache,
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
        $document->setAttribute('$collectionId', $collectionId);

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
                $relatedCollectionDoc = Authorization::skip(
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
                        operations: $operations
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
}
