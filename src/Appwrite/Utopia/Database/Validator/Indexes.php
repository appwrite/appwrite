<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Validator;

class Indexes extends Validator
{
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
    public function __construct(
        protected int $maxIndexes = APP_LIMIT_ARRAY_PARAMS_SIZE,
    ) {
    }

    /**
     * Get Description
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

        foreach ($value as $i => $index) {
            if (!is_array($index)) {
                $this->message = "Index at position $i must be an object";
                return false;
            }

            // Validate required fields
            if (!isset($index['key']) || !is_string($index['key'])) {
                $this->message = "Index at position $i is missing required field 'key'";
                return false;
            }

            if (!isset($index['type']) || !is_string($index['type'])) {
                $this->message = "Index at position $i is missing required field 'type'";
                return false;
            }

            if (!isset($index['attributes']) || !is_array($index['attributes'])) {
                $this->message = "Index at position $i is missing required field 'attributes' (must be an array)";
                return false;
            }

            // Validate key format
            if (!$keyValidator->isValid($index['key'])) {
                $this->message = "Invalid key for index at position $i: " . $keyValidator->getDescription();
                return false;
            }

            // Check for duplicate keys
            if (in_array($index['key'], $keys)) {
                $this->message = "Duplicate index key: " . $index['key'];
                return false;
            }
            $keys[] = $index['key'];

            // Validate type
            if (!in_array($index['type'], $this->supportedTypes)) {
                $this->message = "Invalid type for index '" . $index['key'] . "': " . $index['type'];
                return false;
            }

            // Validate attributes array
            if (empty($index['attributes'])) {
                $this->message = "Index '" . $index['key'] . "' must have at least one attribute";
                return false;
            }

            if (count($index['attributes']) > APP_LIMIT_ARRAY_PARAMS_SIZE) {
                $this->message = "Index '" . $index['key'] . "' cannot have more than " . APP_LIMIT_ARRAY_PARAMS_SIZE . " attributes";
                return false;
            }

            // Validate each attribute in the index
            $systemAttrs = ['$id', '$createdAt', '$updatedAt'];
            foreach ($index['attributes'] as $attrIndex => $attr) {
                if (!is_string($attr)) {
                    $this->message = "Invalid attribute at position $attrIndex in index '" . $index['key'] . "': must be a string";
                    return false;
                }
                if (!$keyValidator->isValid($attr) && !in_array($attr, $systemAttrs)) {
                    $this->message = "Invalid attribute name '$attr' in index '" . $index['key'] . "'";
                    return false;
                }
            }

            $attrCount = count($index['attributes']);

            // Validate orders if provided
            if (isset($index['orders'])) {
                if (!is_array($index['orders'])) {
                    $this->message = "Index '" . $index['key'] . "' orders must be an array";
                    return false;
                }

                if (count($index['orders']) !== $attrCount) {
                    $this->message = "Index '" . $index['key'] . "': orders array length (" . count($index['orders']) . ") must match attributes array length ($attrCount)";
                    return false;
                }

                foreach ($index['orders'] as $order) {
                    if ($order !== null && $order !== '' && !in_array($order, $this->supportedOrders)) {
                        $this->message = "Invalid order '$order' in index '" . $index['key'] . "'. Must be 'ASC' or 'DESC'";
                        return false;
                    }
                }
            }

            // Validate lengths if provided
            if (isset($index['lengths'])) {
                if (!is_array($index['lengths'])) {
                    $this->message = "Index '" . $index['key'] . "' lengths must be an array";
                    return false;
                }

                if (count($index['lengths']) !== $attrCount) {
                    $this->message = "Index '" . $index['key'] . "': lengths array length (" . count($index['lengths']) . ") must match attributes array length ($attrCount)";
                    return false;
                }

                foreach ($index['lengths'] as $length) {
                    if ($length !== null && (!is_int($length) || $length < 0)) {
                        $this->message = "Invalid length in index '" . $index['key'] . "': must be a non-negative integer or null";
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
