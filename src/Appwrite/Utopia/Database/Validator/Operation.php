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
    ];

    /** @var array<string> */
    private array $actions = [
        'create',
        'update',
        'upsert',
        'delete',
        'bulkCreate',
        'bulkUpdate',
        'bulkUpsert',
        'bulkDelete',
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
        if (!\in_array($value['action'], $this->actions, true)) {
            $this->description = "Key 'action' must be one of: " . \implode(', ', $this->actions);
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
