<?php

namespace Appwrite\Utopia\Request\Model\Database\TablesDB;

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Model\Database\Legacy\TransactionOperation as TransactionOperationLegacy;

/**
 * TransactionOperation represents a single operation within a database transaction
 * for the TablesDB API.
 *
 * This model uses tableId/rowId naming convention for SDK generation while internally
 * mapping to the canonical collectionId/documentId fields.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class TransactionOperation extends TransactionOperationLegacy
{
    public function __construct(
        string $databaseId = '',
        string $tableId = '',
        ?string $rowId = null,
        string $action = '',
        array $data = [],
    ) {
        parent::__construct(
            databaseId: $databaseId,
            collectionId: $tableId,
            documentId: $rowId,
            action: $action,
            data: $data,
        );

        // Override rules with TablesDB naming convention
        $this->removeRule('collectionId');
        $this->removeRule('documentId');

        $this
            ->addRule('tableId', [
                'type' => self::TYPE_STRING,
                'description' => 'The ID of the table.',
                'default' => '',
                'example' => '5e5ea5c15117e',
            ])
            ->addRule('rowId', [
                'type' => self::TYPE_STRING,
                'description' => 'The ID of the row. Required for update, upsert, delete, increment, and decrement actions.',
                'default' => null,
                'example' => '5e5ea5c15117e',
                'required' => false,
            ]);
    }

    public function getName(): string
    {
        return 'TablesDB\TransactionOperation';
    }

    public function getType(): string
    {
        return Request::MODEL_TRANSACTION_OPERATION_TABLESDB;
    }

    public function getTableId(): string
    {
        return $this->getCollectionId();
    }

    public function getRowId(): ?string
    {
        return $this->getDocumentId();
    }

    /**
     * Convert array to TransactionOperation model.
     * Supports both TablesDB (tableId/rowId) and legacy (collectionId/documentId) naming conventions.
     *
     * @param array $value
     * @return static
     */
    public static function fromArray(array $value): static
    {
        return new static(
            databaseId: $value['databaseId'] ?? '',
            tableId: $value['tableId'] ?? $value['collectionId'] ?? '',
            rowId: $value['rowId'] ?? $value['documentId'] ?? null,
            action: $value['action'] ?? '',
            data: $value['data'] ?? [],
        );
    }

    /**
     * Convert the model to an array representation using TablesDB naming.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'databaseId' => $this->getDatabaseId(),
            'tableId' => $this->getTableId(),
            'rowId' => $this->getRowId(),
            'action' => $this->getAction(),
            'data' => $this->getData(),
        ];
    }
}
