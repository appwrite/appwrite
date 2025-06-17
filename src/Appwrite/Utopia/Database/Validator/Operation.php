<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator;

class Operation extends Validator
{
    private string $description = '';

    /** @var string[] */
    private array $actions = [
        'create',
        'update',
        'upsert',
        'delete'
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
        $required = ['databaseId', 'collectionId', 'action', 'payload'];
        foreach ($required as $key) {
            if (!\array_key_exists($key, $value)) {
                $this->description = "Missing required key: {$key}";
                return false;
            }
        }

        // databaseId / collectionId / action must be non‑empty strings
        foreach (['databaseId', 'collectionId', 'action'] as $key) {
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

        // Payload must be array (can be empty)
        if (!\is_array($value['payload'])) {
            $this->description = "Key 'payload' must be an array";
            return false;
        }

        return true;
    }

    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
