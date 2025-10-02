<?php

namespace Appwrite\Databases;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * Service for managing transaction state and providing transaction-aware document operations
 */
class TransactionState
{
    private Database $dbForProject;

    public function __construct(Database $dbForProject)
    {
        $this->dbForProject = $dbForProject;
    }

    /**
     * Apply projection (select) semantics from queries to a document
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
     * Get the current state of a transaction by replaying its operations
     */
    private function getTransactionState(string $transactionId): array
    {
        $transaction = $this->dbForProject->getDocument('transactions', $transactionId);
        if ($transaction->isEmpty() || $transaction->getAttribute('status') !== 'pending') {
            return [];
        }

        // Fetch operations ordered by sequence to replay in exact order
        $operations = $this->dbForProject->find('transactionLogs', [
            Query::equal('transactionInternalId', [$transaction->getSequence()]),
        ]);

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
                    if (is_array($data)) {
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
     * Get a document with transaction-aware logic
     */
    public function getDocument(
        string $collectionId,
        string $documentId,
        ?string $transactionId = null,
        array $queries = []
    ): Document {
        // If no transaction, use normal database retrieval
        if ($transactionId === null) {
            return $this->dbForProject->getDocument($collectionId, $documentId, $queries);
        }

        $state = $this->getTransactionState($transactionId);


        // Check if document exists in transaction state
        if (isset($state[$collectionId][$documentId])) {
            $docState = $state[$collectionId][$documentId];

            if (!$docState['exists']) {
                // Document was deleted in transaction
                return new Document();
            }

            if ($docState['action'] === 'create') {
                // Document was created in transaction, return the created version
                return $this->applyProjection($docState['document'], $queries);
            }

            if ($docState['action'] === 'update' || $docState['action'] === 'upsert') {
                // This is an update to an existing document, merge with committed version
                $committedDoc = $this->dbForProject->getDocument($collectionId, $documentId, $queries);
                if (!$committedDoc->isEmpty()) {
                    // Apply the updates from transaction
                    foreach ($docState['document']->getAttributes() as $key => $value) {
                        if ($key !== '$id') {
                            $committedDoc->setAttribute($key, $value);
                        }
                    }
                    // committedDoc already has projection applied by dbForProject->getDocument()
                    // But we need to reapply in case transaction added new fields
                    return $this->applyProjection($committedDoc, $queries);
                } elseif ($docState['action'] === 'upsert') {
                    // Upsert created a new document since committed doc doesn't exist
                    return $this->applyProjection($docState['document'], $queries);
                }
            }
        }

        // Document not affected by transaction, return committed version
        return $this->dbForProject->getDocument($collectionId, $documentId, $queries);
    }

    /**
     * List documents with transaction-aware logic
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
        // We need to find which documents match the filters
        $committedDocs = $this->dbForProject->find($collectionId, $queries);
        $committedDocIds = [];
        foreach ($committedDocs as $doc) {
            $committedDocIds[$doc->getId()] = true;
        }

        $adjustedCount = $baseCount;

        // Apply transaction state changes to the count
        foreach ($state[$collectionId] as $docId => $docState) {
            if (!$docState['exists']) {
                // Document was deleted in transaction
                if (isset($committedDocIds[$docId])) {
                    $adjustedCount--; // Was in results, now deleted
                }
            } elseif ($docState['action'] === 'create') {
                // Document was created in transaction
                // We need to check if it would match the query filters
                // For now, we'll conservatively add it if no filters are present
                // or apply basic filter matching
                if ($this->documentMatchesFilters($docState['document'], $queries)) {
                    $adjustedCount++; // New document that matches
                }
            } elseif ($docState['action'] === 'update' || $docState['action'] === 'upsert') {
                // Document was updated/upserted
                $wasInResults = isset($committedDocIds[$docId]);
                $nowMatches = $this->documentMatchesFilters($docState['document'], $queries);

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
     * Check if a document matches filter queries (simplified implementation)
     */
    private function documentMatchesFilters(Document $doc, array $queries): bool
    {
        // Extract filter queries
        $filters = [];
        foreach ($queries as $query) {
            $method = $query->getMethod();
            // Only process filter queries, not limit/offset/cursor/select
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
                case Query::TYPE_GREATER_THAN:
                    if (!($docValue > $values[0])) {
                        return false;
                    }
                    break;
                case Query::TYPE_GREATER_THAN_EQUAL:
                    if (!($docValue >= $values[0])) {
                        return false;
                    }
                    break;
                case Query::TYPE_LESSER_THAN:
                    if (!($docValue < $values[0])) {
                        return false;
                    }
                    break;
                case Query::TYPE_LESSER_THAN_EQUAL:
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

    /**
     * Check if a document exists with transaction-aware logic
     */
    public function documentExists(
        string $collectionId,
        string $documentId,
        ?string $transactionId = null
    ): bool {
        $doc = $this->getDocument($collectionId, $documentId, $transactionId);
        return !$doc->isEmpty();
    }
}
