<?php

namespace Appwrite\Utopia\Request\Model\Database\Legacy;

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Model;

/**
 * TransactionOperation represents a single operation within a database transaction.
 */
class TransactionOperation extends Model implements \Utopia\Model
{
    public function __construct(
        private readonly string $databaseId = '',
        private readonly string $collectionId = '',
        private readonly ?string $documentId = null,
        private readonly string $action = '',
        private readonly array $data = [],
    ) {
        $this
            ->addRule('databaseId', [
                'type' => self::TYPE_STRING,
                'description' => 'The ID of the database.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('collectionId', [
                'type' => self::TYPE_STRING,
                'description' => 'The ID of the collection.',
                'default' => '',
                'example' => '5e5ea5c15117e',
            ])
            ->addRule('documentId', [
                'type' => self::TYPE_STRING,
                'description' => 'The ID of the document. Required for update, upsert, delete, increment, and decrement actions.',
                'default' => null,
                'example' => '5e5ea5c15117e',
                'required' => false,
            ])
            ->addRule('action', [
                'type' => self::TYPE_STRING,
                'description' => 'The action to perform on the document.',
                'default' => '',
                'example' => 'create',
                'enum' => [
                    'create',
                    'update',
                    'upsert',
                    'delete',
                    'increment',
                    'decrement',
                    'bulkCreate',
                    'bulkUpdate',
                    'bulkUpsert',
                    'bulkDelete',
                ],
            ])
            ->addRule('data', [
                'type' => self::TYPE_JSON,
                'description' => 'The data payload for the operation. Structure depends on the action type.',
                'default' => new \stdClass(),
                'example' => '{"name": "John Doe", "email": "john@example.com"}',
                'required' => false,
            ]);
    }

    public function getName(): string
    {
        return 'Legacy\TransactionOperation';
    }

    public function getType(): string
    {
        return Request::MODEL_TRANSACTION_OPERATION_LEGACY;
    }

    public function getDatabaseId(): string
    {
        return $this->databaseId;
    }

    public function getCollectionId(): string
    {
        return $this->collectionId;
    }

    public function getDocumentId(): ?string
    {
        return $this->documentId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Convert array to TransactionOperation model.
     *
     * @param array $value
     * @return static
     */
    public static function fromArray(array $value): static
    {
        return new static(
            databaseId: $value['databaseId'] ?? '',
            collectionId: $value['collectionId'] ?? $value['tableId'] ?? '',
            documentId: $value['documentId'] ?? $value['rowId'] ?? null,
            action: $value['action'] ?? '',
            data: $value['data'] ?? [],
        );
    }

    /**
     * Convert the model to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'databaseId' => $this->databaseId,
            'collectionId' => $this->collectionId,
            'documentId' => $this->documentId,
            'action' => $this->action,
            'data' => $this->data,
        ];
    }
}
