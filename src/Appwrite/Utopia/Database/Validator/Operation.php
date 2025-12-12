<?php

namespace Appwrite\Utopia\Database\Validator;

use Appwrite\Utopia\Request\Model\Database\Legacy\TransactionOperation as TransactionOperationLegacy;
use Appwrite\Utopia\Request\Model\Database\TablesDB\TransactionOperation as TransactionOperationTablesDB;
use Utopia\Validator;

class Operation extends Validator
{
    private string $description = '';

    /** @var array<string> */
    private array $required = [];

    /** @var array<string, bool> */
    private array $requiresDocumentId = [
        'create' => true,
        'update' => true,
        'upsert' => true,
        'delete' => true,
        'increment' => true,
        'decrement' => true,
    ];

    /** @var array<string, bool> */
    private array $requiresData = [
        'create' => true,
        'update' => true,
        'upsert' => true,
        'delete' => false,  // Delete doesn't need data
        'increment' => true,
        'decrement' => true,
        'bulkCreate' => true,
        'bulkUpdate' => true,
        'bulkUpsert' => true,
        'bulkDelete' => true,
    ];

    /** @var array<string, bool> */
    private array $actions = [];

    private string $collectionIdName = '';
    private string $documentIdName = '';

    public function __construct(private readonly string $type)
    {
        $model = match ($this->type) {
            'legacy' => new TransactionOperationLegacy(),
            'tablesdb' => new TransactionOperationTablesDB(),
            default => throw new \InvalidArgumentException('Invalid type provided.'),
        };

        // Get required fields from the model
        $this->required = $model->getRequired();

        // Get valid actions from the model's action rule enum
        $rules = $model->getRules();
        if (isset($rules['action']['enum'])) {
            foreach ($rules['action']['enum'] as $action) {
                $this->actions[$action] = true;
            }
        }

        // Set collection/document ID names based on model rules
        $this->collectionIdName = isset($rules['tableId']) ? 'tableId' : 'collectionId';
        $this->documentIdName = isset($rules['rowId']) ? 'rowId' : 'documentId';
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCollectionIdKey(): string
    {
        return $this->collectionIdName;
    }

    public function getDocumentIdKey(): string
    {
        return $this->documentIdName;
    }

    public function isArray(): bool
    {
        return true;
    }

    /**
     * @param TransactionOperationLegacy|TransactionOperationTablesDB $value
     */
    public function isValid($value): bool
    {
        $databaseId = $value->getDatabaseId();
        $collectionId = $value->getCollectionId();
        $documentId = $value->getDocumentId();
        $action = $value->getAction();
        $data = $value->getData();

        // Validate databaseId is non-empty
        if (!\is_string($databaseId) || \trim($databaseId) === '') {
            $this->description = "Key 'databaseId' must be a non-empty string";
            return false;
        }

        // Validate collectionId is non-empty
        if (!\is_string($collectionId) || \trim($collectionId) === '') {
            $this->description = "Key '{$this->collectionIdName}' must be a non-empty string";
            return false;
        }

        // Validate action is non-empty
        if (!\is_string($action) || \trim($action) === '') {
            $this->description = "Key 'action' must be a non-empty string";
            return false;
        }

        // Validate action is valid
        if (!isset($this->actions[$action])) {
            $this->description = "Key 'action' must be one of: " . \implode(', ', array_keys($this->actions));
            return false;
        }

        // If action requires documentId, it must be present and non-empty
        $actionRequiresDocumentId = ($this->requiresDocumentId[$action] ?? false) === true;
        if ($actionRequiresDocumentId && ($documentId === null || \trim($documentId) === '')) {
            $this->description = "Key '{$this->documentIdName}' is required for action '{$action}'";
            return false;
        }

        // If documentId is provided, it must be non-empty
        if ($documentId !== null && \trim($documentId) === '') {
            $this->description = "Key '{$this->documentIdName}' must be a non-empty string";
            return false;
        }

        // Data validation - only required for certain actions
        if (isset($this->requiresData[$action]) && $this->requiresData[$action]) {
            // Data is required for this action
            if (empty($data)) {
                $this->description = "Missing required key: data";
                return false;
            }
        }

        // Bulk operation specific validations
        // BulkUpdate and BulkDelete require queries
        if (\in_array($action, ['bulkUpdate', 'bulkDelete'])) {
            if (!\array_key_exists('queries', $data)) {
                $this->description = "Key 'queries' is required in data for {$action}";
                return false;
            }
            if (!\is_array($data['queries'])) {
                $this->description = "Key 'queries' must be an array for {$action}";
                return false;
            }
        }

        // BulkUpdate requires both queries and data
        if ($action === 'bulkUpdate') {
            if (!\array_key_exists('data', $data)) {
                $this->description = "Key 'data' is required in data for {$action}";
                return false;
            }
            if (!\is_array($data['data'])) {
                $this->description = "Key 'data.data' must be an array for {$action}";
                return false;
            }
        }

        // Increment and Decrement require specific keys
        if (\in_array($action, ['increment', 'decrement'])) {
            // Get the attribute key name based on type
            $attributeKey = $this->type === 'tablesdb' ? 'column' : 'attribute';
            if (!\array_key_exists($attributeKey, $data)) {
                $this->description = "Key '{$attributeKey}' is required in data for {$action}";
                return false;
            }
            // Validate 'value' is numeric if provided (defaults to 1 if omitted)
            if (\array_key_exists('value', $data) && !\is_numeric($data['value'])) {
                $this->description = "Key 'value' must be a numeric value for {$action}";
                return false;
            }
        }

        return true;
    }

    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
