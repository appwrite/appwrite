<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * WhiteList
 *
 * Checks if a variable is inside predefined white list.
 */
class WhiteList extends Validator
{
    /**
     * Constructor
     *
     * Sets a white list array and strict mode.
     *
     * @param  bool  $strict disable type check and be case insensetive
     * @param  string  $type of $list items
     */
    public function __construct(protected array $list, protected bool $strict = false, protected string $type = self::TYPE_STRING)
    {
        if (!$this->strict) {
            foreach ($this->list as $key => &$value) {
                $this->list[$key] = strtolower((string) $value);
            }
        }
    }

    /**
     * Get List of All Allowed Values
     */
    public function getList(): array
    {
        return $this->list;
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be one of (' . implode(', ', $this->list) . ')';
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Is valid
     *
     * Validation will pass if $value is in the white list array.
     */
    public function isValid(mixed $value): bool
    {
        if (\is_array($value)) {
            return false;
        }

        $value = ($this->strict) ? $value : strtolower((string) $value);
        return \in_array($value, $this->list, $this->strict);
    }
}
