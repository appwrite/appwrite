<?php

namespace Appwrite\Databases;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * Service for managing transaction state and providing transaction-aware document operations
 *
 * This class provides methods to:
 * - Query documents with transaction awareness (getDocument, listDocuments, countDocuments)
 * - Apply bulk operations to transaction state for cross-operation visibility
 * - Replay transaction operations to build current state
 */
class TransactionState
{
    private Database $dbForProject;

    public function __construct(Database $dbForProject)
    {
        $this->dbForProject = $dbForProject;
    }


    /**
     * Get a document with transaction-aware logic
     *
     * @param string $collectionId Collection ID
     * @param string $documentId Document ID
     * @param string|null $transactionId Optional transaction ID
     * @param array $queries Optional query filters
     * @return Document
     * @throws Exception
     * @throws Exception\Query
     * @throws Timeout
     */
    public function getDocument(
        string $collectionId,
        string $documentId,
        ?string $transactionId = null,
        array $queries = []
    ): Document {
        if ($transactionId === null) {
            return $this->dbForProject->getDocument($collectionId, $documentId, $queries);
        }

        $state = $this->getTransactionState($transactionId);

        if (isset($state[$collectionId][$documentId])) {
            $docState = $state[$collectionId][$documentId];

            if (!$docState['exists']) {
                return new Document();
            }

            if ($docState['action'] === 'create') {
                return $this->applyProjection($docState['document'], $queries);
            }

            if ($docState['action'] === 'update' || $docState['action'] === 'upsert') {
                // Merge with committed version
                $committedDoc = $this->dbForProject->getDocument($collectionId, $documentId, $queries);
                if (!$committedDoc->isEmpty()) {
                    foreach ($docState['document']->getAttributes() as $key => $value) {
                        if ($key !== '$id') {
                            $committedDoc->setAttribute($key, $value);
                        }
                    }
                    // Reapply projection in case transaction added new fields
                    return $this->applyProjection($committedDoc, $queries);
                } elseif ($docState['action'] === 'upsert') {
                    return $this->applyProjection($docState['document'], $queries);
                }
            }
        }

        return $this->dbForProject->getDocument($collectionId, $documentId, $queries);
    }

    /**
     * List documents with transaction-aware logic
     *
     * @param string $collectionId Collection ID
     * @param string|null $transactionId Optional transaction ID
     * @param array $queries Optional query filters
     * @return array Array of Document objects
     * @throws Exception
     * @throws Exception\Query
     * @throws Timeout
     */
    public function listDocuments(
        string $collectionId,
        ?string $transactionId = null,
        array $queries = []
    ): array {
        // If no transaction, use normal database retrieval
        if ($transactionId === null) {
            return $this->dbForProject->find($collectionId, $queries);
        }

        $state = $this->getTransactionState($transactionId);
        $committedDocs = $this->dbForProject->find($collectionId, $queries);
        $documentMap = [];

        // Build map of committed documents
        foreach ($committedDocs as $doc) {
            $documentMap[$doc->getId()] = $doc;
        }

        // Apply transaction state changes
        if (isset($state[$collectionId])) {
            foreach ($state[$collectionId] as $docId => $docState) {
                if (!$docState['exists']) {
                    // Document was deleted, remove from results
                    unset($documentMap[$docId]);
                } elseif ($docState['action'] === 'create') {
                    // Document was created, add to results with projection
                    $documentMap[$docId] = $this->applyProjection($docState['document'], $queries);
                } elseif ($docState['action'] === 'update' || $docState['action'] === 'upsert') {
                    if (isset($documentMap[$docId])) {
                        // Update existing document
                        foreach ($docState['document']->getAttributes() as $key => $value) {
                            if ($key !== '$id') {
                                $documentMap[$docId]->setAttribute($key, $value);
                            }
                        }
                        // Reapply projection in case transaction added new fields
                        $documentMap[$docId] = $this->applyProjection($documentMap[$docId], $queries);
                    } elseif ($docState['action'] === 'upsert') {
                        // Upsert created a new document, apply projection
                        $documentMap[$docId] = $this->applyProjection($docState['document'], $queries);
                    }
                }
            }
        }

        return array_values($documentMap);
    }

    /**
     * Count documents with transaction-aware logic
     *
     * @param string $collectionId Collection ID
     * @param string|null $transactionId Optional transaction ID
     * @param array $queries Optional query filters
     * @return int Document count
     * @throws Exception
     * @throws Exception\Query
     * @throws Timeout
     */
    public function countDocuments(
        string $collectionId,
        ?string $transactionId = null,
        array $queries = []
    ): int {
        // If no transaction, use normal database count
        if ($transactionId === null) {
            return $this->dbForProject->count($collectionId, $queries, APP_LIMIT_COUNT);
        }

        $state = $this->getTransactionState($transactionId);

        // Get base count from database
        $baseCount = $this->dbForProject->count($collectionId, $queries, APP_LIMIT_COUNT);

        // If no transaction state for this collection, return base count
        if (!isset($state[$collectionId])) {
            return $baseCount;
        }

        // Build a set of committed document IDs that match the query
        $committedDocs = $this->dbForProject->find($collectionId, $queries);
        $committedDocIds = [];
        foreach ($committedDocs as $doc) {
            $committedDocIds[$doc->getId()] = true;
        }

        $adjustedCount = $baseCount;

        $filters = $this->extractFilters($queries);

        // Apply transaction state changes to the count
        foreach ($state[$collectionId] as $docId => $docState) {
            if (!$docState['exists']) {
                // Document was deleted in transaction
                if (isset($committedDocIds[$docId])) {
                    $adjustedCount--; // Was in results, now deleted
                }
            } elseif ($docState['action'] === 'create') {
                // Document was created in transaction
                if ($this->documentMatchesFilters($docState['document'], $filters)) {
                    $adjustedCount++; // New document that matches
                }
            } elseif ($docState['action'] === 'update' || $docState['action'] === 'upsert') {
                // Document was updated/upserted
                $wasInResults = isset($committedDocIds[$docId]);
                $nowMatches = $this->documentMatchesFilters($docState['document'], $filters);

                if (!$wasInResults && $nowMatches && $docState['action'] === 'upsert') {
                    $adjustedCount++; // Upsert created new document that matches
                } elseif ($wasInResults && !$nowMatches) {
                    $adjustedCount--; // Update/upsert made document no longer match
                } elseif (!$wasInResults && $nowMatches) {
                    // Update shouldn't add a new doc, but upsert might have
                    if ($docState['action'] === 'upsert') {
                        $adjustedCount++;
                    }
                }
            }
        }

        return max(0, $adjustedCount);
    }

    /**
     * Check if a document exists with transaction-aware logic
     *
     * @param string $collectionId Collection ID
     * @param string $documentId Document ID
     * @param string|null $transactionId Optional transaction ID
     * @return bool True if document exists
     */
    public function documentExists(
        string $collectionId,
        string $documentId,
        ?string $transactionId = null
    ): bool {
        $doc = $this->getDocument($collectionId, $documentId, $transactionId);
        return !$doc->isEmpty();
    }

    /**
     * Apply bulk update to documents in transaction state that match queries
     *
     * This allows bulk operations within a transaction to see each other's changes.
     *
     * @param string $collectionId Collection ID
     * @param Document $updateData Document with update values
     * @param array $queries Query filters to match documents
     * @param array &$state Transaction state (passed by reference)
     * @return void
     */
    public function applyBulkUpdateToState(
        string $collectionId,
        Document $updateData,
        array $queries,
        array &$state
    ): void {
        if (!isset($state[$collectionId])) {
            return;
        }

        $filters = $this->extractFilters($queries);

        foreach ($state[$collectionId] as $docId => $doc) {
            if ($this->documentMatchesFilters($doc, $filters)) {
                // Apply the update to the state document
                foreach ($updateData->getArrayCopy() as $key => $value) {
                    if ($key !== '$id') {
                        $doc->setAttribute($key, $value);
                    }
                }
            }
        }
    }

    /**
     * Apply bulk delete to documents in transaction state that match queries
     *
     * This allows bulk operations within a transaction to see each other's changes.
     *
     * @param string $collectionId Collection ID
     * @param array $queries Query filters to match documents
     * @param array &$state Transaction state (passed by reference)
     * @return void
     */
    public function applyBulkDeleteToState(
        string $collectionId,
        array $queries,
        array &$state
    ): void {
        if (!isset($state[$collectionId])) {
            return;
        }

        $filters = $this->extractFilters($queries);

        foreach ($state[$collectionId] as $docId => $doc) {
            if ($this->documentMatchesFilters($doc, $filters)) {
                unset($state[$collectionId][$docId]);
            }
        }
    }

    /**
     * Apply bulk upsert to documents in transaction state
     *
     * This merges partial upsert data with full documents from transaction state,
     * preventing validation errors when upserting documents created in the same transaction.
     *
     * @param string $collectionId Collection ID
     * @param array $documents Array of Document objects to upsert (can be partial)
     * @param array &$state Transaction state (passed by reference)
     * @return array Merged documents ready for database upsert
     */
    public function applyBulkUpsertToState(
        string $collectionId,
        array $documents,
        array &$state
    ): array {
        $mergedDocuments = [];

        foreach ($documents as $doc) {
            if (!($doc instanceof Document)) {
                continue;
            }

            $docId = $doc->getId();
            if (!$docId) {
                continue;
            }

            // If document exists in state, update it and use the merged version
            if (isset($state[$collectionId][$docId])) {
                // Apply updates to existing state document
                foreach ($doc->getArrayCopy() as $key => $value) {
                    if ($key !== '$id') {
                        $state[$collectionId][$docId]->setAttribute($key, $value);
                    }
                }
                // Use the full merged document from state
                $mergedDocuments[] = $state[$collectionId][$docId];
            } else {
                // Document not in state - use original partial data for DB upsert
                $mergedDocuments[] = $doc;
            }
        }

        return $mergedDocuments;
    }

    /**
     * Get the current state of a transaction by replaying its operations
     *
     * @param string $transactionId Transaction ID
     * @return array State array with structure: [collectionId => [docId => ['action' => ..., 'document' => ..., 'exists' => ...]]]
     * @throws Exception
     * @throws Exception\Query
     * @throws Timeout
     */
    private function getTransactionState(string $transactionId): array
    {
        $transaction = Authorization::skip(fn () => $this->dbForProject->getDocument('transactions', $transactionId));
        if ($transaction->isEmpty() || $transaction->getAttribute('status') !== 'pending') {
            return [];
        }

        // Fetch operations ordered by sequence to replay in exact order
        $operations = Authorization::skip(fn () => $this->dbForProject->find('transactionLogs', [
            Query::equal('transactionInternalId', [$transaction->getSequence()]),
            Query::orderAsc(),
            Query::limit(PHP_INT_MAX)
        ]));

        $state = [];

        foreach ($operations as $operation) {
            $databaseInternalId = $operation['databaseInternalId'];
            $collectionInternalId = $operation['collectionInternalId'];
            $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";
            $documentId = $operation['documentId'];
            $action = $operation['action'];
            $data = $operation['data'];

            if ($data instanceof Document) {
                $data = $data->getArrayCopy();
            }

            switch ($action) {
                case 'create':
                    $docId = $documentId ?? ($data['$id'] ?? null);
                    if ($docId) {
                        // Ensure document has the correct $id
                        if (!isset($data['$id'])) {
                            $data['$id'] = $docId;
                        }
                        $state[$collectionId][$docId] = [
                            'action' => 'create',
                            'document' => new Document($data),
                            'exists' => true
                        ];
                    }
                    break;

                case 'update':
                    if (isset($state[$collectionId][$documentId])) {
                        // Update existing document in transaction state
                        $existingDocument = $state[$collectionId][$documentId]['document'];
                        foreach ($data as $key => $value) {
                            if ($key !== '$id') {
                                $existingDocument->setAttribute($key, $value);
                            }
                        }
                        // Only set action to 'update' if it's not already 'create' or 'upsert'
                        $currentAction = $state[$collectionId][$documentId]['action'];
                        if ($currentAction !== 'create' && $currentAction !== 'upsert') {
                            $state[$collectionId][$documentId]['action'] = 'update';
                        }
                    } else {
                        // Document doesn't exist in transaction state, will be merged with committed version
                        $state[$collectionId][$documentId] = [
                            'action' => 'update',
                            'document' => new Document($data),
                            'exists' => true
                        ];
                    }
                    break;

                case 'upsert':
                    $docId = $documentId ?? ($data['$id'] ?? null);
                    if (!$docId) {
                        break;
                    }
                    $state[$collectionId][$docId] = [
                        'action' => 'upsert',
                        'document' => new Document($data),
                        'exists' => true
                    ];
                    break;

                case 'delete':
                    $state[$collectionId][$documentId] = [
                        'action' => 'delete',
                        'exists' => false
                    ];
                    break;

                case 'bulkCreate':
                    if (\is_array($data)) {
                        foreach ($data as $doc) {
                            if ($doc instanceof Document) {
                                $doc = $doc->getArrayCopy();
                            }
                            $state[$collectionId][$doc['$id']] = [
                                'action' => 'create',
                                'document' => new Document($doc),
                                'exists' => true
                            ];
                        }
                    }
                    break;
            }
        }

        return $state;
    }

    /**
     * Apply projection (select) semantics from queries to a document
     *
     * @param Document $doc Document to apply projection to
     * @param array $queries Query array that may contain select queries
     * @return Document Projected document
     */
    private function applyProjection(Document $doc, array $queries): Document
    {
        if (empty($queries)) {
            return $doc;
        }

        // Extract selections from queries
        $selections = [];
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_SELECT) {
                $values = $query->getValues();
                foreach ($values as $value) {
                    // Skip relationship selections (containing '.')
                    if (!\str_contains($value, '.')) {
                        $selections[] = $value;
                    }
                }
            }
        }

        // If no selections or wildcard present, return document as-is
        if (empty($selections) || \in_array('*', $selections)) {
            return $doc;
        }

        // Create a new document with only selected attributes
        $projected = new Document();

        // Always preserve internal attributes
        $projected->setAttribute('$id', $doc->getId());
        $projected->setAttribute('$collection', $doc->getCollection());
        $projected->setAttribute('$createdAt', $doc->getCreatedAt());
        $projected->setAttribute('$updatedAt', $doc->getUpdatedAt());
        if ($doc->offsetExists('$permissions')) {
            $projected->setAttribute('$permissions', $doc->getPermissions());
        }

        // Add selected attributes
        foreach ($selections as $attribute) {
            if ($doc->offsetExists($attribute)) {
                $projected->setAttribute($attribute, $doc->getAttribute($attribute));
            }
        }

        return $projected;
    }

    /**
     * Extract only filter queries from a query array
     *
     * @param array $queries Query array
     * @return array Filtered queries
     */
    private function extractFilters(array $queries): array
    {
        $filters = [];
        foreach ($queries as $query) {
            $method = $query->getMethod();
            // Only process filter queries, not limit/offset/cursor/select/order
            if (!\in_array($method, [
                Query::TYPE_LIMIT,
                Query::TYPE_OFFSET,
                Query::TYPE_CURSOR_AFTER,
                Query::TYPE_CURSOR_BEFORE,
                Query::TYPE_SELECT,
                Query::TYPE_ORDER_ASC,
                Query::TYPE_ORDER_DESC
            ])) {
                $filters[] = $query;
            }
        }
        return $filters;
    }

    /**
     * Check if a document matches filter queries
     *
     * @param Document $doc Document to check
     * @param array $filters Pre-filtered Query filters (use extractFilters first)
     * @return bool True if document matches all filters
     */
    private function documentMatchesFilters(Document $doc, array $filters): bool
    {
        // If no filters, document matches
        if (empty($filters)) {
            return true;
        }

        // Check each filter
        foreach ($filters as $filter) {
            $attribute = $filter->getAttribute();
            $values = $filter->getValues();
            $docValue = $doc->getAttribute($attribute);

            switch ($filter->getMethod()) {
                case Query::TYPE_EQUAL:
                    if (!\in_array($docValue, $values)) {
                        return false;
                    }
                    break;

                case Query::TYPE_NOT_EQUAL:
                    if (\in_array($docValue, $values)) {
                        return false;
                    }
                    break;

                case Query::TYPE_CONTAINS:
                    $matches = false;
                    foreach ($values as $value) {
                        if (\is_array($docValue) && \in_array($value, $docValue)) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        return false;
                    }
                    break;

                case Query::TYPE_STARTS_WITH:
                    $matches = false;
                    foreach ($values as $value) {
                        if (\is_string($docValue) && \str_starts_with($docValue, $value)) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        return false;
                    }
                    break;

                case Query::TYPE_ENDS_WITH:
                    $matches = false;
                    foreach ($values as $value) {
                        if (\is_string($docValue) && \str_ends_with($docValue, $value)) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        return false;
                    }
                    break;

                case Query::TYPE_GREATER:
                    if (!($docValue > $values[0])) {
                        return false;
                    }
                    break;

                case Query::TYPE_GREATER_EQUAL:
                    if (!($docValue >= $values[0])) {
                        return false;
                    }
                    break;

                case Query::TYPE_LESSER:
                    if (!($docValue < $values[0])) {
                        return false;
                    }
                    break;

                case Query::TYPE_LESSER_EQUAL:
                    if (!($docValue <= $values[0])) {
                        return false;
                    }
                    break;

                case Query::TYPE_IS_NULL:
                    if (!\is_null($docValue)) {
                        return false;
                    }
                    break;

                case Query::TYPE_IS_NOT_NULL:
                    if (\is_null($docValue)) {
                        return false;
                    }
                    break;

                case Query::TYPE_BETWEEN:
                    if (!($docValue >= $values[0] && $docValue <= $values[1])) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }
}
