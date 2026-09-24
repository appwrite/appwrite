<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Text
 *
 * Validate that an variable is a valid text value
 */
class Text extends Validator
{
    public const NUMBERS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public const ALPHABET_UPPER = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

    public const ALPHABET_LOWER = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z'];

    /**
     * Text constructor.
     *
     * Validate text with maximum length $length. Use $length = 0 for unlimited length.
     * Optionally, provide allowList characters array $allowList to only allow specific character.
     * Set $requireNonBlank to reject values containing only Unicode separators, whitespace, or formatting characters.
     *
     * @param  string[]  $allowList
     */
    public function __construct(
        protected int $length,
        protected int $min = 1,
        protected array $allowList = [],
        protected bool $requireNonBlank = false,
    ) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        $message = 'Value must be a valid string';

        if ($this->min === $this->length) {
            $message .= ' and exactly ' . $this->length . ' chars';
        } else {
            if ($this->min !== 0) {
                $message .= ' and at least ' . $this->min . ' chars';
            }

            if ($this->length !== 0) {
                $message .= ' and no longer than ' . $this->length . ' chars';
            }
        }

        if ($this->allowList) {
            $message .= ' and only consist of \'' . implode(', ', $this->allowList) . '\' chars';
        }

        if ($this->requireNonBlank) {
            $message .= ' and not be blank';
        }

        return $message;
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
        return self::TYPE_STRING;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is text with valid length.
     */
    public function isValid(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if ($this->requireNonBlank && preg_match('/[^\p{Z}\p{Cf}\s]/u', $value) !== 1) {
            return false;
        }

        if (mb_strlen($value) < $this->min) {
            return false;
        }

        if (mb_strlen($value) > $this->length && $this->length !== 0) {
            return false;
        }

        if (\count($this->allowList) > 0) {
            foreach (str_split($value) as $char) {
                if (!\in_array($char, $this->allowList)) {
                    return false;
                }
            }
        }

        return true;
    }
}
