<?php

declare(strict_types=1);

namespace Utopia\Validator;

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

        if ($bits > \PHP_INT_SIZE * 8 - ($unsigned ? 1 : 0)) {
            throw new \InvalidArgumentException(\sprintf('%d-bit %s integers do not fit the %d-bit integers of this PHP build', $bits, $unsigned ? 'unsigned' : 'signed', \PHP_INT_SIZE * 8));
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

        return \sprintf(
            'Value must be a valid %s %d-bit integer between %s and %s',
            $signedness,
            $this->bits,
            \number_format($this->getMin()),
            \number_format($this->getMax()),
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

    private function getMin(): int
    {
        return $this->unsigned ? 0 : -$this->getMax() - 1;
    }

    private function getMax(): int
    {
        return $this->unsigned ? (1 << $this->bits) - 1 : \PHP_INT_MAX >> (\PHP_INT_SIZE * 8 - $this->bits);
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

        return $value >= $this->getMin() && $value <= $this->getMax();
    }
}
