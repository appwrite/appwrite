<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator;

class Operation extends Validator
{
    private string $description = '';

    /** @var array<string> */
    private array $required = [
        'databaseId',
        'collectionId',
        'action',
        'data',
    ];

    /** @var array<string, bool> */
    private array $requiresDocumentId = [
        'create' => true,
        'update' => true,
        'upsert' => true,
        'delete' => true,
    ];

    /** @var array<string, bool> */
    private array $actions = [
        'create' => true,
        'update' => true,
        'upsert' => true,
        'delete' => true,
        'bulkCreate' => true,
        'bulkUpdate' => true,
        'bulkUpsert' => true,
        'bulkDelete' => true,
    ];

    public function getDescription(): string
    {
        return $this->description;
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
            $this->description = "Key 'action' must be one of: " . \implode(', ', $this->actions);
            return false;
        }

        // If action requires documentId, it must be present
        if (
            isset($this->requiresDocumentId[$value['action']]) &&
            !\array_key_exists('documentId', $value)
        ) {
            $this->description = "Key 'documentId' is required for action '{$value['action']}'";
            return false;
        }

        // Data must be array (can be empty)
        if (!\is_array($value['data'])) {
            $this->description = "Key 'data' must be an array";
            return false;
        }

        return true;
    }

    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
