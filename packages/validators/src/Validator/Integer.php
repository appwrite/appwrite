<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Integer
 *
 * Validate that an variable is an integer
 */
class Integer extends Validator
{
    protected int $bits = 32;

    protected bool $unsigned = false;

    /**
     * Pass true to accept integer strings as valid integer values
     * This option is good for validating query string params.
     *
     * @param  int  $bits  Integer bit size (8, 16, 32, or 64)
     * @param  bool  $unsigned  Whether the integer is unsigned
     * @throws \InvalidArgumentException
     */
    public function __construct(protected bool $loose = false, int $bits = 32, bool $unsigned = false)
    {
        if (!\in_array($bits, [8, 16, 32, 64])) {
            throw new \InvalidArgumentException('Bits must be 8, 16, 32, or 64');
        }

        // 64-bit unsigned integers exceed PHP_INT_MAX and convert to floats with precision loss
        if ($bits === 64 && $unsigned) {
            throw new \InvalidArgumentException('64-bit unsigned integers are not supported due to PHP integer limitations');
        }
        $this->bits = $bits;
        $this->unsigned = $unsigned;
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        $signedness = $this->unsigned ? 'unsigned' : 'signed';

        // Calculate min and max values based on bit size and signed/unsigned
        if ($this->unsigned) {
            $min = 0;
            $max = (2 ** $this->bits) - 1;
        } else {
            $min = -(2 ** ($this->bits - 1));
            $max = (2 ** ($this->bits - 1)) - 1;
        }

        return \sprintf(
            'Value must be a valid %s %d-bit integer between %s and %s',
            $signedness,
            $this->bits,
            number_format($min),
            number_format($max),
        );
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
        return self::TYPE_INTEGER;
    }

    /**
     * Get Bits
     *
     * Returns the bit size of the integer.
     */
    public function getBits(): int
    {
        return $this->bits;
    }

    /**
     * Is Unsigned
     *
     * Returns whether the integer is unsigned.
     */
    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    /**
     * Get Format
     *
     * Returns the OpenAPI/JSON Schema format string for this integer configuration.
     */
    public function getFormat(): string
    {
        $prefix = $this->isUnsigned() ? 'uint' : 'int';
        return $prefix . $this->bits;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is integer and within the specified bit range.
     */
    public function isValid(mixed $value): bool
    {
        if ($this->loose) {
            if (!is_numeric($value)) {
                return false;
            }
            $value += 0;
        }
        if (!\is_int($value)) {
            return false;
        }

        // Calculate min and max values based on bit size and signed/unsigned
        if ($this->unsigned) {
            $min = 0;
            $max = (2 ** $this->bits) - 1;
        } else {
            $min = -(2 ** ($this->bits - 1));
            $max = (2 ** ($this->bits - 1)) - 1;
        }
        // Check if value is within range
        return $value >= $min && $value <= $max;
    }
}
