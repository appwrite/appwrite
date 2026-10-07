<?php

namespace Utopia\Database;

use JsonException;
use Utopia\Database\Exception\Operator as OperatorException;

/**
 * Operator class for atomic database operations
 *
 * This class provides a structured way to perform atomic operations
 * such as increment, decrement, and array manipulations on database documents.
 */
class Operator
{
    // Numeric operation types
    public const TYPE_INCREMENT = 'increment';
    public const TYPE_DECREMENT = 'decrement';
    public const TYPE_MODULO = 'modulo';
    public const TYPE_POWER = 'power';
    public const TYPE_MULTIPLY = 'multiply';
    public const TYPE_DIVIDE = 'divide';

    // Array operation types
    public const TYPE_ARRAY_APPEND = 'arrayAppend';
    public const TYPE_ARRAY_PREPEND = 'arrayPrepend';
    public const TYPE_ARRAY_INSERT = 'arrayInsert';
    public const TYPE_ARRAY_REMOVE = 'arrayRemove';
    public const TYPE_ARRAY_UNIQUE = 'arrayUnique';
    public const TYPE_ARRAY_INTERSECT = 'arrayIntersect';
    public const TYPE_ARRAY_DIFF = 'arrayDiff';
    public const TYPE_ARRAY_FILTER = 'arrayFilter';

    // String operation types
    public const TYPE_STRING_CONCAT = 'stringConcat';
    public const TYPE_STRING_REPLACE = 'stringReplace';

    // Boolean operation types
    public const TYPE_TOGGLE = 'toggle';

    // Date operation types
    public const TYPE_DATE_ADD_DAYS = 'dateAddDays';
    public const TYPE_DATE_SUB_DAYS = 'dateSubDays';
    public const TYPE_DATE_SET_NOW = 'dateSetNow';

    public const TYPES = [
        self::TYPE_INCREMENT,
        self::TYPE_DECREMENT,
        self::TYPE_MULTIPLY,
        self::TYPE_DIVIDE,
        self::TYPE_MODULO,
        self::TYPE_POWER,
        self::TYPE_STRING_CONCAT,
        self::TYPE_STRING_REPLACE,
        self::TYPE_ARRAY_APPEND,
        self::TYPE_ARRAY_PREPEND,
        self::TYPE_ARRAY_INSERT,
        self::TYPE_ARRAY_REMOVE,
        self::TYPE_ARRAY_UNIQUE,
        self::TYPE_ARRAY_INTERSECT,
        self::TYPE_ARRAY_DIFF,
        self::TYPE_ARRAY_FILTER,
        self::TYPE_TOGGLE,
        self::TYPE_DATE_ADD_DAYS,
        self::TYPE_DATE_SUB_DAYS,
        self::TYPE_DATE_SET_NOW,
    ];

    /**
     * Maximum number of values a single array operator (append/prepend/intersect/diff) may carry,
     * to guard against memory exhaustion. Enforced consistently across all adapters.
     */
    public const MAX_ARRAY_OPERATOR_SIZE = 10000;

    /**
     * Conditions accepted by the arrayFilter operator.
     */
    public const ARRAY_FILTER_CONDITIONS = [
        'equal', 'notEqual',                                            // comparison
        'greaterThan', 'greaterThanEqual', 'lessThan', 'lessThanEqual', // numeric
        'isNull', 'isNotNull',                                          // null checks
    ];

    protected const NUMERIC_TYPES = [
        self::TYPE_INCREMENT,
        self::TYPE_DECREMENT,
        self::TYPE_MULTIPLY,
        self::TYPE_DIVIDE,
        self::TYPE_MODULO,
        self::TYPE_POWER,
    ];

    protected const ARRAY_TYPES = [
        self::TYPE_ARRAY_APPEND,
        self::TYPE_ARRAY_PREPEND,
        self::TYPE_ARRAY_INSERT,
        self::TYPE_ARRAY_REMOVE,
        self::TYPE_ARRAY_UNIQUE,
        self::TYPE_ARRAY_INTERSECT,
        self::TYPE_ARRAY_DIFF,
        self::TYPE_ARRAY_FILTER,
    ];

    protected const STRING_TYPES = [
        self::TYPE_STRING_CONCAT,
        self::TYPE_STRING_REPLACE,
    ];

    protected const BOOLEAN_TYPES = [
        self::TYPE_TOGGLE,
    ];


    protected const DATE_TYPES = [
        self::TYPE_DATE_ADD_DAYS,
        self::TYPE_DATE_SUB_DAYS,
        self::TYPE_DATE_SET_NOW,
    ];

    protected string $method = '';
    protected string $attribute = '';

    /**
     * @var array<mixed>
     */
    protected array $values = [];

    /**
     * Construct a new operator object
     *
     * @param string $method
     * @param string $attribute
     * @param array<mixed> $values
     */
    public function __construct(string $method, string $attribute = '', array $values = [])
    {
        $this->method = $method;
        $this->attribute = $attribute;
        $this->values = $values;
    }

    public function __clone(): void
    {
        foreach ($this->values as $index => $value) {
            if ($value instanceof self) {
                $this->values[$index] = clone $value;
            }
        }
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function getAttribute(): string
    {
        return $this->attribute;
    }

    /**
     * @return array<mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function getValue(mixed $default = null): mixed
    {
        return $this->values[0] ?? $default;
    }

    /**
     * Sets method
     *
     * @param string $method
     * @return self
     */
    public function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Sets attribute
     *
     * @param string $attribute
     * @return self
     */
    public function setAttribute(string $attribute): self
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Sets values
     *
     * @param array<mixed> $values
     * @return self
     */
    public function setValues(array $values): self
    {
        $this->values = $values;

        return $this;
    }

    /**
     * Sets value
     * @param mixed $value
     * @return self
     */
    public function setValue(mixed $value): self
    {
        $this->values = [$value];

        return $this;
    }

    /**
     * Check if method is supported
     *
     * @param string $value
     * @return bool
     */
    public static function isMethod(string $value): bool
    {
        return match ($value) {
            self::TYPE_INCREMENT,
            self::TYPE_DECREMENT,
            self::TYPE_MULTIPLY,
            self::TYPE_DIVIDE,
            self::TYPE_MODULO,
            self::TYPE_POWER,
            self::TYPE_STRING_CONCAT,
            self::TYPE_STRING_REPLACE,
            self::TYPE_ARRAY_APPEND,
            self::TYPE_ARRAY_PREPEND,
            self::TYPE_ARRAY_INSERT,
            self::TYPE_ARRAY_REMOVE,
            self::TYPE_ARRAY_UNIQUE,
            self::TYPE_ARRAY_INTERSECT,
            self::TYPE_ARRAY_DIFF,
            self::TYPE_ARRAY_FILTER,
            self::TYPE_TOGGLE,
            self::TYPE_DATE_ADD_DAYS,
            self::TYPE_DATE_SUB_DAYS,
            self::TYPE_DATE_SET_NOW => true,
            default => false,
        };
    }

    /**
     * Check if method is a numeric operation
     *
     * @return bool
     */
    public function isNumericOperation(): bool
    {
        return \in_array($this->method, self::NUMERIC_TYPES);
    }

    /**
     * Check if method is an array operation
     *
     * @return bool
     */
    public function isArrayOperation(): bool
    {
        return \in_array($this->method, self::ARRAY_TYPES);
    }

    /**
     * Check if method is a string operation
     *
     * @return bool
     */
    public function isStringOperation(): bool
    {
        return \in_array($this->method, self::STRING_TYPES);
    }

    /**
     * Check if method is a boolean operation
     *
     * @return bool
     */
    public function isBooleanOperation(): bool
    {
        return \in_array($this->method, self::BOOLEAN_TYPES);
    }


    /**
     * Check if method is a date operation
     *
     * @return bool
     */
    public function isDateOperation(): bool
    {
        return \in_array($this->method, self::DATE_TYPES);
    }

    /**
     * Parse operator from string
     *
     * @param string $operator
     * @return self
     * @throws OperatorException
     */
    public static function parse(string $operator): self
    {
        try {
            $operator = \json_decode($operator, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OperatorException('Invalid operator: ' . $e->getMessage());
        }

        if (!\is_array($operator)) {
            throw new OperatorException('Invalid operator. Must be an array, got ' . \gettype($operator));
        }

        return self::parseOperator($operator);
    }

    /**
     * Parse operator from array
     *
     * @param array<string, mixed> $operator
     * @return self
     * @throws OperatorException
     */
    public static function parseOperator(array $operator): self
    {
        $method = $operator['method'] ?? '';
        $attribute = $operator['attribute'] ?? '';
        $values = $operator['values'] ?? [];

        if (!\is_string($method)) {
            throw new OperatorException('Invalid operator method. Must be a string, got ' . \gettype($method));
        }

        if (!self::isMethod($method)) {
            throw new OperatorException('Invalid operator method: ' . $method);
        }

        if (!\is_string($attribute)) {
            throw new OperatorException('Invalid operator attribute. Must be a string, got ' . \gettype($attribute));
        }

        if (!\is_array($values)) {
            throw new OperatorException('Invalid operator values. Must be an array, got ' . \gettype($values));
        }

        return new self($method, $attribute, $values);
    }

    /**
     * Parse an array of operators
     *
     * @param array<string> $operators
     *
     * @return array<Operator>
     * @throws OperatorException
     */
    public static function parseOperators(array $operators): array
    {
        $parsed = [];

        foreach ($operators as $operator) {
            $parsed[] = self::parse($operator);
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'attribute' => $this->attribute,
            'values' => $this->values,
        ];
    }

    /**
     * @return string
     * @throws OperatorException
     */
    public function toString(): string
    {
        try {
            return \json_encode($this->toArray(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OperatorException('Invalid Json: ' . $e->getMessage());
        }
    }

    /**
     * Helper method to create increment operator
     *
     * @param int|float $value
     * @param int|float|null $max Maximum value (won't increment beyond this)
     * @return Operator
     */
    public static function increment(int|float $value = 1, int|float|null $max = null): self
    {
        $values = [$value];
        if ($max !== null) {
            $values[] = $max;
        }
        return new self(self::TYPE_INCREMENT, '', $values);
    }

    /**
     * Helper method to create decrement operator
     *
     * @param int|float $value
     * @param int|float|null $min Minimum value (won't decrement below this)
     * @return Operator
     */
    public static function decrement(int|float $value = 1, int|float|null $min = null): self
    {
        $values = [$value];
        if ($min !== null) {
            $values[] = $min;
        }
        return new self(self::TYPE_DECREMENT, '', $values);
    }


    /**
     * Helper method to create array append operator
     *
     * @param array<mixed> $values
     * @return Operator
     */
    public static function arrayAppend(array $values): self
    {
        return new self(self::TYPE_ARRAY_APPEND, '', $values);
    }

    /**
     * Helper method to create array prepend operator
     *
     * @param array<mixed> $values
     * @return Operator
     */
    public static function arrayPrepend(array $values): self
    {
        return new self(self::TYPE_ARRAY_PREPEND, '', $values);
    }

    /**
     * Helper method to create array insert operator
     *
     * @param int $index
     * @param mixed $value
     * @return Operator
     */
    public static function arrayInsert(int $index, mixed $value): self
    {
        return new self(self::TYPE_ARRAY_INSERT, '', [$index, $value]);
    }

    /**
     * Helper method to create array remove operator
     *
     * @param mixed $value
     * @return Operator
     */
    public static function arrayRemove(mixed $value): self
    {
        return new self(self::TYPE_ARRAY_REMOVE, '', [$value]);
    }

    /**
     * Helper method to create concatenation operator
     *
     * @param mixed $value Value to concatenate (string or array)
     * @return Operator
     */
    public static function stringConcat(mixed $value): self
    {
        return new self(self::TYPE_STRING_CONCAT, '', [$value]);
    }

    /**
     * Helper method to create replace operator
     *
     * @param string $search
     * @param string $replace
     * @return Operator
     */
    public static function stringReplace(string $search, string $replace): self
    {
        return new self(self::TYPE_STRING_REPLACE, '', [$search, $replace]);
    }

    /**
     * Helper method to create multiply operator
     *
     * @param int|float $factor
     * @param int|float|null $max Maximum value (won't multiply beyond this)
     * @return Operator
     */
    public static function multiply(int|float $factor, int|float|null $max = null): self
    {
        $values = [$factor];
        if ($max !== null) {
            $values[] = $max;
        }
        return new self(self::TYPE_MULTIPLY, '', $values);
    }

    /**
     * Helper method to create divide operator
     *
     * @param int|float $divisor
     * @param int|float|null $min Minimum value (won't divide below this)
     * @return Operator
     * @throws OperatorException if divisor is zero
     */
    public static function divide(int|float $divisor, int|float|null $min = null): self
    {
        if ($divisor == 0) {
            throw new OperatorException('Division by zero is not allowed');
        }
        $values = [$divisor];
        if ($min !== null) {
            $values[] = $min;
        }
        return new self(self::TYPE_DIVIDE, '', $values);
    }

    /**
     * Helper method to create toggle operator
     *
     * @return Operator
     */
    public static function toggle(): self
    {
        return new self(self::TYPE_TOGGLE, '', []);
    }


    /**
     * Helper method to create date add days operator
     *
     * @param int $days Number of days to add (can be negative to subtract)
     * @return Operator
     */
    public static function dateAddDays(int $days): self
    {
        return new self(self::TYPE_DATE_ADD_DAYS, '', [$days]);
    }

    /**
     * Helper method to create date subtract days operator
     *
     * @param int $days Number of days to subtract
     * @return Operator
     */
    public static function dateSubDays(int $days): self
    {
        return new self(self::TYPE_DATE_SUB_DAYS, '', [$days]);
    }

    /**
     * Helper method to create date set now operator
     *
     * @return Operator
     */
    public static function dateSetNow(): self
    {
        return new self(self::TYPE_DATE_SET_NOW, '', []);
    }

    /**
     * Helper method to create modulo operator
     *
     * @param int|float $divisor The divisor for modulo operation
     * @return Operator
     * @throws OperatorException if divisor is zero
     */
    public static function modulo(int|float $divisor): self
    {
        if ($divisor == 0) {
            throw new OperatorException('Modulo by zero is not allowed');
        }
        return new self(self::TYPE_MODULO, '', [$divisor]);
    }

    /**
     * Helper method to create power operator
     *
     * @param int|float $exponent The exponent to raise to
     * @param int|float|null $max Maximum value (won't exceed this)
     * @return Operator
     */
    public static function power(int|float $exponent, int|float|null $max = null): self
    {
        $values = [$exponent];
        if ($max !== null) {
            $values[] = $max;
        }
        return new self(self::TYPE_POWER, '', $values);
    }


    /**
     * Helper method to create array unique operator
     *
     * @return Operator
     */
    public static function arrayUnique(): self
    {
        return new self(self::TYPE_ARRAY_UNIQUE, '', []);
    }

    /**
     * Helper method to create array intersect operator
     *
     * @param array<mixed> $values Values to intersect with current array
     * @return Operator
     */
    public static function arrayIntersect(array $values): self
    {
        return new self(self::TYPE_ARRAY_INTERSECT, '', $values);
    }

    /**
     * Helper method to create array diff operator
     *
     * @param array<mixed> $values Values to remove from current array
     * @return Operator
     */
    public static function arrayDiff(array $values): self
    {
        return new self(self::TYPE_ARRAY_DIFF, '', $values);
    }

    /**
     * Helper method to create array filter operator
     *
     * @param string $condition Filter condition ('equals', 'notEquals', 'greaterThan', 'lessThan', 'null', 'notNull')
     * @param mixed $value Value to filter by (not used for 'null'/'notNull' conditions)
     * @return Operator
     */
    public static function arrayFilter(string $condition, mixed $value = null): self
    {
        return new self(self::TYPE_ARRAY_FILTER, '', [$condition, $value]);
    }

    /**
     * Check if a value is an operator instance
     *
     * @param mixed $value
     * @return bool
     */
    public static function isOperator(mixed $value): bool
    {
        return $value instanceof self;
    }

    /**
     * Extract operators from document data
     *
     * @param array<string, mixed> $data
     * @return array{operators: array<string, Operator>, updates: array<string, mixed>}
     */
    public static function extractOperators(array $data): array
    {
        $operators = [];
        $updates = [];

        foreach ($data as $key => $value) {
            if (self::isOperator($value)) {
                // Set the attribute from the document key if not already set
                if (empty($value->getAttribute())) {
                    $value->setAttribute($key);
                }
                $operators[$key] = $value;
            } else {
                $updates[$key] = $value;
            }
        }

        return [
            'operators' => $operators,
            'updates' => $updates,
        ];
    }

}
