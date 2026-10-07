<?php

namespace Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Operator as DatabaseOperator;
use Utopia\Validator;

class Operator extends Validator
{
    protected Document $collection;

    /**
     * @var array<string, Document|array<string, mixed>>
     */
    protected array $attributes = [];

    protected string $message = 'Invalid operator';

    protected ?Document $currentDocument = null;

    /**
     * Constructor
     *
     * @param Document $collection
     * @param Document|null $currentDocument Current document for runtime validation (e.g., array bounds checking)
     */
    public function __construct(Document $collection, ?Document $currentDocument = null)
    {
        $this->collection = $collection;
        $this->currentDocument = $currentDocument;

        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            $this->attributes[$attribute->getAttribute('key', $attribute->getId())] = $attribute;
        }
    }

    /**
     * Check if a value is a valid relationship reference (string ID or Document)
     *
     * @param mixed $item
     * @return bool
     */
    private function isValidRelationshipValue(mixed $item): bool
    {
        return \is_string($item) || $item instanceof Document;
    }

    /**
     * Check if a relationship attribute represents a "many" side (returns array of documents)
     *
     * @param Document|array<string, mixed> $attribute
     * @return bool
     */
    private function isRelationshipArray(Document|array $attribute): bool
    {
        $options = $attribute instanceof Document
            ? $attribute->getAttribute('options', [])
            : ($attribute['options'] ?? []);

        $relationType = $options['relationType'] ?? '';
        $side = $options['side'] ?? '';

        // Many-to-many is always an array on both sides
        if ($relationType === Database::RELATION_MANY_TO_MANY) {
            return true;
        }

        // One-to-many: array on parent side, single on child side
        if ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_PARENT) {
            return true;
        }

        // Many-to-one: array on child side, single on parent side
        if ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_CHILD) {
            return true;
        }

        return false;
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
     * Returns true if valid or false if not.
     *
     * @param $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!$value instanceof DatabaseOperator) {
            try {
                $value = DatabaseOperator::parse($value);
            } catch (\Throwable $e) {
                $this->message = 'Invalid operator: ' . $e->getMessage();
                return false;
            }
        }

        $method = $value->getMethod();
        $attribute = $value->getAttribute();

        // Check if method is valid
        if (!DatabaseOperator::isMethod($method)) {
            $this->message = "Invalid operator method: {$method}";
            return false;
        }

        // Check if attribute exists in collection
        $attributeConfig = $this->attributes[$attribute] ?? null;
        if ($attributeConfig === null) {
            $this->message = "Attribute '{$attribute}' does not exist in collection";
            return false;
        }

        // Validate operator against attribute type
        return $this->validateOperatorForAttribute($value, $attributeConfig);
    }

    /**
     * Validate operator against attribute configuration
     *
     * @param DatabaseOperator $operator
     * @param Document|array<string, mixed> $attribute
     * @return bool
     */
    private function validateOperatorForAttribute(
        DatabaseOperator $operator,
        Document|array $attribute
    ): bool {
        $method = $operator->getMethod();
        $values = $operator->getValues();

        // Handle both Document objects and arrays
        $type = $attribute instanceof Document ? $attribute->getAttribute('type') : $attribute['type'];
        $isArray = $attribute instanceof Document ? ($attribute->getAttribute('array') ?? false) : ($attribute['array'] ?? false);

        // Array operators that carry a caller-supplied value list are capped to guard against
        // memory exhaustion. Enforced here so every adapter rejects an oversized list the same way.
        // The payload may be spread across $values or wrapped in $values[0] (the same shape the
        // operators normalize with), so measure whichever the operator will actually process.
        if (
            \in_array($method, [
                DatabaseOperator::TYPE_ARRAY_APPEND,
                DatabaseOperator::TYPE_ARRAY_PREPEND,
                DatabaseOperator::TYPE_ARRAY_INTERSECT,
                DatabaseOperator::TYPE_ARRAY_DIFF,
                DatabaseOperator::TYPE_ARRAY_REMOVE,
            ], true)
        ) {
            $payload = (isset($values[0]) && \is_array($values[0])) ? $values[0] : $values;
            if (\count($payload) > DatabaseOperator::MAX_ARRAY_OPERATOR_SIZE) {
                $this->message = "Array size " . \count($payload) . " exceeds maximum allowed size of " . DatabaseOperator::MAX_ARRAY_OPERATOR_SIZE . " for array operations";
                return false;
            }
        }

        switch ($method) {
            case DatabaseOperator::TYPE_INCREMENT:
            case DatabaseOperator::TYPE_DECREMENT:
            case DatabaseOperator::TYPE_MULTIPLY:
            case DatabaseOperator::TYPE_DIVIDE:
            case DatabaseOperator::TYPE_MODULO:
            case DatabaseOperator::TYPE_POWER:
                // Numeric operations only work on numeric types
                if (!\in_array($type, [Database::VAR_INTEGER, Database::VAR_FLOAT])) {
                    $this->message = "Cannot apply {$method} operator to non-numeric field '{$operator->getAttribute()}'";
                    return false;
                }

                // Validate the numeric value and optional max/min
                if (!isset($values[0]) || !\is_numeric($values[0])) {
                    $this->message = "Cannot apply {$method} operator: value must be numeric, got " . gettype($operator->getValue());
                    return false;
                }

                // Special validation for divide/modulo by zero
                if (($method === DatabaseOperator::TYPE_DIVIDE || $method === DatabaseOperator::TYPE_MODULO) && (float)$values[0] === 0.0) {
                    $this->message = "Cannot apply {$method} operator: " . ($method === DatabaseOperator::TYPE_DIVIDE ? "division" : "modulo") . " by zero";
                    return false;
                }

                // Validate max/min if provided
                if (\count($values) > 1 && $values[1] !== null && !\is_numeric($values[1])) {
                    $this->message = "Cannot apply {$method} operator: max/min limit must be numeric, got " . \gettype($values[1]);
                    return false;
                }

                if ($this->currentDocument !== null && $type === Database::VAR_INTEGER && !isset($values[1])) {
                    $currentValue = $this->currentDocument->getAttribute($operator->getAttribute()) ?? 0;
                    $operatorValue = $values[0];

                    // Compute predicted result
                    $predictedResult = match ($method) {
                        DatabaseOperator::TYPE_INCREMENT => $currentValue + $operatorValue,
                        DatabaseOperator::TYPE_DECREMENT => $currentValue - $operatorValue,
                        DatabaseOperator::TYPE_MULTIPLY => $currentValue * $operatorValue,
                        DatabaseOperator::TYPE_DIVIDE => $currentValue / $operatorValue,
                        DatabaseOperator::TYPE_MODULO => $currentValue % $operatorValue,
                        DatabaseOperator::TYPE_POWER => $currentValue ** $operatorValue,
                    };

                    if ($predictedResult > Database::MAX_INT) {
                        $this->message = "Cannot apply {$method} operator: would overflow maximum value of " . Database::MAX_INT;
                        return false;
                    }

                    if ($predictedResult < Database::MIN_INT) {
                        $this->message = "Cannot apply {$method} operator: would underflow minimum value of " . Database::MIN_INT;
                        return false;
                    }
                }

                break;
            case DatabaseOperator::TYPE_ARRAY_APPEND:
            case DatabaseOperator::TYPE_ARRAY_PREPEND:
                // For relationships, check if it's a "many" side
                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$this->isRelationshipArray($attribute)) {
                        $this->message = "Cannot apply {$method} operator to single-value relationship '{$operator->getAttribute()}'";
                        return false;
                    }
                    foreach ($values as $item) {
                        if (!$this->isValidRelationshipValue($item)) {
                            $this->message = "Cannot apply {$method} operator: relationship values must be document IDs (strings) or Document objects";
                            return false;
                        }
                    }
                } elseif (!$isArray) {
                    $this->message = "Cannot apply {$method} operator to non-array field '{$operator->getAttribute()}'";
                    return false;
                }

                if (!empty($values) && $type === Database::VAR_INTEGER) {
                    $newItems = \is_array($values[0]) ? $values[0] : $values;
                    foreach ($newItems as $item) {
                        if (\is_numeric($item) && ($item > Database::MAX_INT || $item < Database::MIN_INT)) {
                            $this->message = "Cannot apply {$method} operator: array items must be between " . Database::MIN_INT . " and " . Database::MAX_INT;
                            return false;
                        }
                    }
                }

                break;
            case DatabaseOperator::TYPE_ARRAY_UNIQUE:
                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$this->isRelationshipArray($attribute)) {
                        $this->message = "Cannot apply {$method} operator to single-value relationship '{$operator->getAttribute()}'";
                        return false;
                    }
                } elseif (!$isArray) {
                    $this->message = "Cannot apply {$method} operator to non-array field '{$operator->getAttribute()}'";
                    return false;
                }

                break;
            case DatabaseOperator::TYPE_ARRAY_INSERT:
                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$this->isRelationshipArray($attribute)) {
                        $this->message = "Cannot apply {$method} operator to single-value relationship '{$operator->getAttribute()}'";
                        return false;
                    }
                } elseif (!$isArray) {
                    $this->message = "Cannot apply {$method} operator to non-array field '{$operator->getAttribute()}'";
                    return false;
                }

                if (\count($values) !== 2) {
                    $this->message = "Cannot apply {$method} operator: requires exactly 2 values (index and value)";
                    return false;
                }

                $index = $values[0];
                if (!\is_int($index) || $index < 0) {
                    $this->message = "Cannot apply {$method} operator: index must be a non-negative integer";
                    return false;
                }

                $insertValue = $values[1];

                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$this->isValidRelationshipValue($insertValue)) {
                        $this->message = "Cannot apply {$method} operator: relationship values must be document IDs (strings) or Document objects";
                        return false;
                    }
                }

                if ($type === Database::VAR_INTEGER && \is_numeric($insertValue)) {
                    if ($insertValue > Database::MAX_INT || $insertValue < Database::MIN_INT) {
                        $this->message = "Cannot apply {$method} operator: array items must be between " . Database::MIN_INT . " and " . Database::MAX_INT;
                        return false;
                    }
                }

                // Runtime validation: Check if index is within bounds
                if ($this->currentDocument !== null) {
                    $currentArray = $this->currentDocument->getAttribute($operator->getAttribute());
                    if (\is_array($currentArray)) {
                        $arrayLength = \count($currentArray);
                        // Valid indices are 0 to length (inclusive, as we can append)
                        if ($index > $arrayLength) {
                            $this->message = "Cannot apply {$method} operator: index {$index} is out of bounds for array of length {$arrayLength}";
                            return false;
                        }
                    }
                }

                break;
            case DatabaseOperator::TYPE_ARRAY_REMOVE:
                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$this->isRelationshipArray($attribute)) {
                        $this->message = "Cannot apply {$method} operator to single-value relationship '{$operator->getAttribute()}'";
                        return false;
                    }
                    $toValidate = \is_array($values[0]) ? $values[0] : $values;
                    foreach ($toValidate as $item) {
                        if (!$this->isValidRelationshipValue($item)) {
                            $this->message = "Cannot apply {$method} operator: relationship values must be document IDs (strings) or Document objects";
                            return false;
                        }
                    }
                } elseif (!$isArray) {
                    $this->message = "Cannot apply {$method} operator to non-array field '{$operator->getAttribute()}'";
                    return false;
                }

                if (empty($values)) {
                    $this->message = "Cannot apply {$method} operator: requires a value to remove";
                    return false;
                }

                break;
            case DatabaseOperator::TYPE_ARRAY_INTERSECT:
                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$this->isRelationshipArray($attribute)) {
                        $this->message = "Cannot apply {$method} operator to single-value relationship '{$operator->getAttribute()}'";
                        return false;
                    }
                } elseif (!$isArray) {
                    $this->message = "Cannot use {$method} operator on non-array attribute '{$operator->getAttribute()}'";
                    return false;
                }

                if (empty($values)) {
                    $this->message = "{$method} operator requires a non-empty array value";
                    return false;
                }

                if ($type === Database::VAR_RELATIONSHIP) {
                    foreach ($values as $item) {
                        if (!$this->isValidRelationshipValue($item)) {
                            $this->message = "Cannot apply {$method} operator: relationship values must be document IDs (strings) or Document objects";
                            return false;
                        }
                    }
                }

                break;
            case DatabaseOperator::TYPE_ARRAY_DIFF:
                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$this->isRelationshipArray($attribute)) {
                        $this->message = "Cannot apply {$method} operator to single-value relationship '{$operator->getAttribute()}'";
                        return false;
                    }
                    foreach ($values as $item) {
                        if (!$this->isValidRelationshipValue($item)) {
                            $this->message = "Cannot apply {$method} operator: relationship values must be document IDs (strings) or Document objects";
                            return false;
                        }
                    }
                } elseif (!$isArray) {
                    $this->message = "Cannot use {$method} operator on non-array attribute '{$operator->getAttribute()}'";
                    return false;
                }

                break;
            case DatabaseOperator::TYPE_ARRAY_FILTER:
                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$this->isRelationshipArray($attribute)) {
                        $this->message = "Cannot apply {$method} operator to single-value relationship '{$operator->getAttribute()}'";
                        return false;
                    }
                } elseif (!$isArray) {
                    $this->message = "Cannot apply {$method} operator to non-array field '{$operator->getAttribute()}'";
                    return false;
                }

                if (\count($values) < 1 || \count($values) > 2) {
                    $this->message = "Cannot apply {$method} operator: requires 1 or 2 values (condition and optional comparison value)";
                    return false;
                }

                if (!\is_string($values[0])) {
                    $this->message = "Cannot apply {$method} operator: condition must be a string";
                    return false;
                }

                if (!\in_array($values[0], DatabaseOperator::ARRAY_FILTER_CONDITIONS, true)) {
                    $this->message = "Invalid array filter condition '{$values[0]}'. Must be one of: " . \implode(', ', DatabaseOperator::ARRAY_FILTER_CONDITIONS);
                    return false;
                }

                break;
            case DatabaseOperator::TYPE_STRING_CONCAT:
                if (!in_array($type, Database::STRING_TYPES) || $isArray) {
                    $this->message = "Cannot apply {$method} operator to non-string field '{$operator->getAttribute()}'";
                    return false;
                }

                if (empty($values) || !\is_string($values[0])) {
                    $this->message = "Cannot apply {$method} operator: requires a string value";
                    return false;
                }

                if ($this->currentDocument !== null && in_array($type, Database::STRING_TYPES)) {
                    $currentString = $this->currentDocument->getAttribute($operator->getAttribute()) ?? '';
                    $concatValue = $values[0];
                    $predictedLength = strlen($currentString) + strlen($concatValue);

                    $maxSize = $attribute instanceof Document
                        ? $attribute->getAttribute('size', 0)
                        : ($attribute['size'] ?? 0);

                    if ($maxSize > 0 && $predictedLength > $maxSize) {
                        $this->message = "Cannot apply {$method} operator: result would exceed maximum length of {$maxSize} characters";
                        return false;
                    }
                }

                break;
            case DatabaseOperator::TYPE_STRING_REPLACE:
                // Replace only works on string types
                if (!in_array($type, Database::STRING_TYPES)) {
                    $this->message = "Cannot apply {$method} operator to non-string field '{$operator->getAttribute()}'";
                    return false;
                }

                if (\count($values) !== 2 || !\is_string($values[0]) || !\is_string($values[1])) {
                    $this->message = "Cannot apply {$method} operator: requires exactly 2 string values (search and replace)";
                    return false;
                }

                break;
            case DatabaseOperator::TYPE_TOGGLE:
                // Toggle only works on boolean types
                if ($type !== Database::VAR_BOOLEAN) {
                    $this->message = "Cannot apply {$method} operator to non-boolean field '{$operator->getAttribute()}'";
                    return false;
                }

                break;
            case DatabaseOperator::TYPE_DATE_ADD_DAYS:
            case DatabaseOperator::TYPE_DATE_SUB_DAYS:
                if ($type !== Database::VAR_DATETIME) {
                    $this->message = "Cannot apply {$method} operator to non-datetime field '{$operator->getAttribute()}'";
                    return false;
                }

                if (empty($values) || !\is_int($values[0])) {
                    $this->message = "Cannot apply {$method} operator: requires an integer number of days";
                    return false;
                }

                break;
            case DatabaseOperator::TYPE_DATE_SET_NOW:
                if ($type !== Database::VAR_DATETIME) {
                    $this->message = "Cannot apply {$method} operator to non-datetime field '{$operator->getAttribute()}'";
                    return false;
                }

                break;
            default:
                $this->message = "Cannot apply {$method} operator: unsupported operator method";
                return false;
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
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
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
