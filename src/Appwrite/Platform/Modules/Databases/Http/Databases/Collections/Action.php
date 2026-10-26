<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Action as DatabasesAction;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

abstract class Action extends DatabasesAction
{
    /**
     * The current API context (either 'table' or 'collection').
     */
    private string $context = COLLECTIONS;

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    public function setHttpPath(string $path): self
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = TABLES;
        }
        parent::setHttpPath($path);
        return $this;
    }

    /**
     * Get the current API context.
     */
    protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Get the key used in event parameters (e.g., 'collectionId' or 'tableId').
     */
    protected function getEventsParamKey(): string
    {
        return $this->getContext() . 'Id';
    }

    /**
     * Determine if the current action is for the Collections API.
     */
    protected function isCollectionsAPI(): bool
    {
        return $this->getContext() === COLLECTIONS;
    }

    /**
     * Get the SDK group name for the current action.
     */
    protected function getSDKGroup(): string
    {
        return $this->isCollectionsAPI() ? 'collections' : 'tables';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    protected function getSDKNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'databases' : 'tablesDB';
    }

    /**
     * Get the exception to throw when the resource already exists.
     */
    protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_ALREADY_EXISTS
            : Exception::TABLE_ALREADY_EXISTS;
    }

    /**
     * Get the appropriate index invalid exception.
     */
    protected function getInvalidIndexException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_INVALID
            : Exception::COLUMN_INDEX_INVALID;
    }

    /**
     * Get the exception to throw when the resource is not found.
     */
    protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the exception to throw when the resource limit is exceeded.
     */
    protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_LIMIT_EXCEEDED
            : Exception::TABLE_LIMIT_EXCEEDED;
    }

    /**
     * Add row bytes information to a collection/table document.
     */
    protected function addRowBytesInfo(Document $document, Database $dbForProject): Document
    {
        $adapter = $dbForProject->getAdapter();
        $document->setAttribute('bytesMax', $adapter->limits()->documentSize);
        $document->setAttribute('bytesUsed', $adapter->getAttributeWidth($document));
        return $document;
    }

    /**
     * Undo a create that failed after the backing collection was made.
     *
     * The metadata row is written first so its sequence can name the collection, which leaves
     * a window where both exist but the schema documents behind them do not. The caller is
     * told the create failed, so nothing it can still see should survive it.
     *
     * @param  array<Document>  $attributeDocuments
     * @param  array<Document>  $indexDocuments
     */
    protected function cleanup(
        Database $dbForProject,
        Database $dbForDatabases,
        Document $database,
        Document $collection,
        array $attributeDocuments = [],
        array $indexDocuments = []
    ): void {
        $databaseKey = 'database_' . $database->getSequence();

        // Cleanup is best effort -- the caller is already being told the create failed, and a
        // failure to undo it must not replace that with a second one. What it leaves behind is
        // invisible to the caller, so each failure is logged with the id needed to find it.
        try {
            $dbForDatabases->deleteCollection($databaseKey . '_collection_' . $collection->getSequence());
        } catch (\Throwable $e) {
            Console::warning('Failed to remove collection ' . $collection->getId() . ' after a failed create: ' . $e->getMessage());
        }

        // The schema is written as two batches, so the second can fail with the first stored.
        // These are removed one id at a time, by the ids the caller built -- not by a query
        // over the schema collections. Those collections are shared by every project on a
        // shared-tables host, so a rollback must never be able to select rows it did not
        // write, whatever the tenant scoping does.
        foreach (['attributes' => $attributeDocuments, 'indexes' => $indexDocuments] as $schema => $documents) {
            foreach ($documents as $document) {
                try {
                    $dbForProject->deleteDocument($schema, $document->getId());
                } catch (\Throwable $e) {
                    Console::warning('Failed to remove ' . $schema . ' ' . $document->getId() . ' after a failed create: ' . $e->getMessage());
                }
            }
        }

        $this->cleanupDocument($dbForProject, $databaseKey, $collection->getId());
    }

    /**
     * Undo a create that failed before the backing collection was made.
     *
     * Only the metadata row exists at that point, so this is the whole of the rollback. It
     * swallows its own failure for the same reason cleanup() does: the caller is already
     * being handed the error that stopped the create, and losing it to a second one would
     * turn a clear refusal into an unexplained server error.
     */
    protected function cleanupDocument(
        Database $dbForProject,
        string $databaseId,
        string $collectionDocumentId
    ): void {
        try {
            $dbForProject->deleteDocument($databaseId, $collectionDocumentId);
        } catch (\Throwable $e) {
            Console::warning('Failed to remove row ' . $collectionDocumentId . ' from ' . $databaseId . ' after a failed create: ' . $e->getMessage());
        }
    }
}
