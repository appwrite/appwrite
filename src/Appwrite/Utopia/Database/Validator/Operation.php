<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator;

class Operation extends Validator
{
    private string $description = '';

    /** @var array<string> */
    private array $required = [
        'databaseId',
        'action',
    ];

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
    private array $actions = [
        'create' => true,
        'update' => true,
        'upsert' => true,
        'delete' => true,
        'increment' => true,
        'decrement' => true,
        'bulkCreate' => true,
        'bulkUpdate' => true,
        'bulkUpsert' => true,
        'bulkDelete' => true,
    ];

    private string $collectionIdName = '';
    private string $documentIdName = '';

    public function __construct(private readonly string $type)
    {
        switch ($this->type) {
            case 'legacy':
                $this->collectionIdName = 'collectionId';
                $this->documentIdName = 'documentId';
                break;
            case 'tablesdb':
                $this->collectionIdName = 'tableId';
                $this->documentIdName = 'rowId';
                break;
            default:
                throw new \InvalidArgumentException('Invalid type provided.');
        }

        $this->required[] = $this->collectionIdName;
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
     * @param mixed $value
     */
    public function isValid($value): bool
    {
        // Must be array‑like
        if (!\is_array($value)) {
            $this->description = 'Value must be an array';
            return false;
        }

        // Mandatory keys
        foreach ($this->required as $key) {
            if (!\array_key_exists($key, $value)) {
                $this->description = "Missing required key: {$key}";
                return false;
            }
        }

        // Required keys must be non‑empty
        foreach ($this->required as $key) {
            if (!\is_string($value[$key]) || \trim($value[$key]) === '') {
                $this->description = "Key '{$key}' must be a non‑empty string";
                return false;
            }
        }

        // Validate action
        if (!isset($this->actions[$value['action']])) {
            $this->description = "Key 'action' must be one of: " . \implode(', ', array_keys($this->actions));
            return false;
        }

        // If action requires documentId, it must be present
        $actionRequiresDocumentId = ($this->requiresDocumentId[$value['action']] ?? false) === true;
        if ($actionRequiresDocumentId && !\array_key_exists($this->documentIdName, $value)) {
            $this->description = "Key '$this->documentIdName' is required for action '{$value['action']}'";
            return false;
        }

        if (\array_key_exists($this->documentIdName, $value)) {
            if (!\is_string($value[$this->documentIdName]) || \trim($value[$this->documentIdName]) === '') {
                $this->description = "Key '$this->documentIdName' must be a non-empty string";
                return false;
            }
        }

        // Data validation - only required for certain actions
        if (isset($this->requiresData[$value['action']]) && $this->requiresData[$value['action']]) {
            // Data is required for this action
            if (!\array_key_exists('data', $value)) {
                $this->description = "Missing required key: data";
                return false;
            }
            if (!\is_array($value['data'])) {
                $this->description = "Key 'data' must be an array";
                return false;
            }
        } elseif (\array_key_exists('data', $value)) {
            // Data is optional but if provided, must be an array
            if (!\is_array($value['data'])) {
                $this->description = "Key 'data' must be an array";
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
