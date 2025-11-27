<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Validator;

class Indexes extends Validator
{
    protected int $maxIndexes;
    protected string $message = 'Invalid indexes';

    /**
     * @var array<string> Supported index types
     */
    protected array $supportedTypes = [
        Database::INDEX_KEY,
        Database::INDEX_FULLTEXT,
        Database::INDEX_UNIQUE,
        Database::INDEX_SPATIAL,
    ];

    /**
     * @var array<string> Supported orders
     */
    protected array $supportedOrders = [
        Database::ORDER_ASC,
        Database::ORDER_DESC,
    ];

    /**
     * @param int $maxIndexes Maximum number of indexes allowed
     */
    public function __construct(int $maxIndexes = APP_LIMIT_ARRAY_PARAMS_SIZE)
    {
        $this->maxIndexes = $maxIndexes;
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->message;
    }

    /**
     * Is valid
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!is_array($value)) {
            $this->message = 'Indexes must be an array';
            return false;
        }

        if (count($value) > $this->maxIndexes) {
            $this->message = 'Maximum of ' . $this->maxIndexes . ' indexes allowed';
            return false;
        }

        $keyValidator = new Key();
        $keys = [];

        foreach ($value as $index => $indexDef) {
            if (!is_array($indexDef)) {
                $this->message = "Index at position $index must be an object";
                return false;
            }

            // Validate required fields
            if (!isset($indexDef['key'])) {
                $this->message = "Index at position $index is missing required field 'key'";
                return false;
            }

            if (!isset($indexDef['type'])) {
                $this->message = "Index at position $index is missing required field 'type'";
                return false;
            }

            if (!isset($indexDef['attributes']) || !is_array($indexDef['attributes'])) {
                $this->message = "Index at position $index is missing required field 'attributes' (must be an array)";
                return false;
            }

            // Validate key
            if (!$keyValidator->isValid($indexDef['key'])) {
                $this->message = "Invalid key for index at position $index: " . $keyValidator->getDescription();
                return false;
            }

            // Check for duplicate keys
            if (in_array($indexDef['key'], $keys)) {
                $this->message = "Duplicate index key: " . $indexDef['key'];
                return false;
            }
            $keys[] = $indexDef['key'];

            // Validate type
            if (!in_array($indexDef['type'], $this->supportedTypes)) {
                $this->message = "Invalid type for index '" . $indexDef['key'] . "': " . $indexDef['type'];
                return false;
            }

            // Validate attributes array
            if (empty($indexDef['attributes'])) {
                $this->message = "Index '" . $indexDef['key'] . "' must have at least one attribute";
                return false;
            }

            if (count($indexDef['attributes']) > APP_LIMIT_ARRAY_PARAMS_SIZE) {
                $this->message = "Index '" . $indexDef['key'] . "' cannot have more than " . APP_LIMIT_ARRAY_PARAMS_SIZE . " attributes";
                return false;
            }

            // Validate each attribute in the index
            foreach ($indexDef['attributes'] as $attrIndex => $attr) {
                if (!is_string($attr)) {
                    $this->message = "Invalid attribute at position $attrIndex in index '" . $indexDef['key'] . "': must be a string";
                    return false;
                }
                if (!$keyValidator->isValid($attr) && !in_array($attr, ['$id', '$createdAt', '$updatedAt'])) {
                    $this->message = "Invalid attribute name '$attr' in index '" . $indexDef['key'] . "'";
                    return false;
                }
            }

            // Validate orders if provided
            if (isset($indexDef['orders'])) {
                if (!is_array($indexDef['orders'])) {
                    $this->message = "Index '" . $indexDef['key'] . "' orders must be an array";
                    return false;
                }

                foreach ($indexDef['orders'] as $order) {
                    if ($order !== null && $order !== '' && !in_array($order, $this->supportedOrders)) {
                        $this->message = "Invalid order '$order' in index '" . $indexDef['key'] . "'. Must be 'ASC' or 'DESC'";
                        return false;
                    }
                }
            }

            // Validate lengths if provided
            if (isset($indexDef['lengths'])) {
                if (!is_array($indexDef['lengths'])) {
                    $this->message = "Index '" . $indexDef['key'] . "' lengths must be an array";
                    return false;
                }

                foreach ($indexDef['lengths'] as $length) {
                    if ($length !== null && (!is_int($length) || $length < 0)) {
                        $this->message = "Invalid length in index '" . $indexDef['key'] . "': must be a non-negative integer or null";
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Is array
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }
}
