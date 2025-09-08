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
            Query::orderAsc('$createdAt'), // Ensure operations are processed in order
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
                    if ($documentId) {
                        $state[$collectionId][$documentId] = [
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
                        $state[$collectionId][$documentId]['action'] = 'update';
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
                    $state[$collectionId][$documentId] = [
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
                return $docState['document'];
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
                    return $committedDoc;
                } elseif ($docState['action'] === 'upsert') {
                    // Upsert created a new document since committed doc doesn't exist
                    return $docState['document'];
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
                    // Document was created, add to results
                    $documentMap[$docId] = $docState['document'];
                } elseif ($docState['action'] === 'update' || $docState['action'] === 'upsert') {
                    if (isset($documentMap[$docId])) {
                        // Update existing document
                        foreach ($docState['document']->getAttributes() as $key => $value) {
                            if ($key !== '$id') {
                                $documentMap[$docId]->setAttribute($key, $value);
                            }
                        }
                    } elseif ($docState['action'] === 'upsert') {
                        // Upsert created a new document
                        $documentMap[$docId] = $docState['document'];
                    }
                }
            }
        }

        return array_values($documentMap);
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
