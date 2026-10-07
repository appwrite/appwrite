<?php

namespace Utopia\Database\Validator;

use Utopia\Validator;

class Datetime extends Validator
{
    public const PRECISION_DAYS = 'days';
    public const PRECISION_HOURS = 'hours';
    public const PRECISION_MINUTES = 'minutes';
    public const PRECISION_SECONDS = 'seconds';
    public const PRECISION_ANY = 'any';

    /**
     * @throws \Exception
     */
    public function __construct(
        private readonly \DateTime $min = new \DateTime('0000-01-01'),
        private readonly \DateTime $max = new \DateTime('9999-12-31'),
        private readonly bool $requireDateInFuture = false,
        private readonly string $precision = self::PRECISION_ANY,
        private readonly int $offset = 0,
    ) {
        if ($offset < 0) {
            throw new \Exception('Offset must be a positive integer.');
        }
    }

    /**
     * Validator Description.
     * @return string
     */
    public function getDescription(): string
    {
        $message = 'Value must be valid date';

        if ($this->offset > 0) {
            $message .= " at least " . $this->offset . " seconds in the future and";
        } elseif ($this->requireDateInFuture) {
            $message .= " in the future and";
        }

        if ($this->precision !== self::PRECISION_ANY) {
            $message .= " with " . $this->precision . " precision";
        }

        $min = $this->min->format('Y-m-d H:i:s');
        $max = $this->max->format('Y-m-d H:i:s');

        $message .= " between {$min} and {$max}.";
        return $message;
    }

    /**
     * Is valid.
     * Returns true if valid or false if not.
     * @param mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (empty($value) || ! is_string($value)) {
            return false;
        }

        try {
            $date = new \DateTime($value);
            $now = new \DateTime();

            if ($this->requireDateInFuture === true && $date < $now) {
                return false;
            }

            if ($this->offset !== 0) {
                $diff = $date->getTimestamp() - $now->getTimestamp();
                if ($diff <= $this->offset) {
                    return false;
                }
            }

            // Constants from: https://www.php.net/manual/en/datetime.format.php
            $denyConstants = [];

            switch ($this->precision) {
                case self::PRECISION_DAYS:
                    $denyConstants = [ 'H', 'i', 's', 'v' ];
                    break;
                case self::PRECISION_HOURS:
                    $denyConstants = [ 'i', 's', 'v' ];
                    break;
                case self::PRECISION_MINUTES:
                    $denyConstants = [ 's', 'v' ];
                    break;
                case self::PRECISION_SECONDS:
                    $denyConstants = [ 'v' ];
                    break;
            }

            foreach ($denyConstants as $constant) {
                if (\intval($date->format($constant)) !== 0) {
                    return false;
                }
            }
        } catch (\Exception) {
            return false;
        }

        // Custom year validation to account for PHP allowing year overflow
        $matches = [];
        if (preg_match('/(?<!\d)(\d{4})(?!\d)/', $value, $matches)) {
            $year = (int)$matches[1];
            $minYear = (int)$this->min->format('Y');
            $maxYear = (int)$this->max->format('Y');
            if ($year < $minYear || $year > $maxYear) {
                return false;
            }
        } else {
            return false;
        }

        if ($date < $this->min || $date > $this->max) {
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
        return self::TYPE_STRING;
    }
}
