<?php

namespace Appwrite\Functions\Validator;

use Utopia\System\System;
use Utopia\Validator;

const MEMORY_VALUES = [512, 1024, 2048, 4096, 8192, 16384];

class Memory extends Validator
{
    private static function filterBelowThreshold(array $inputArray, int $threshold): array
    {
        return \array_filter($inputArray, function ($value) use ($threshold) {
            return $value <= $threshold;
        });
    }

    /**
     * Get Allowed Values.
     *
     * Get allowed values taking into account the limits set by the environment variables.
     *
     * @return array
     */
    public static function getAllowedValues(): array
    {
        return self::filterBelowThreshold(MEMORY_VALUES, System::getEnv('_APP_FUNCTIONS_MEMORY', 1024));
    }

    /**
     * Get Description.
     *
     * Returns validator description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Integer must be a valid memory value of ' . self::filterBelowThreshold(MEMORY_VALUES, System::getEnv('_APP_FUNCTIONS_MEMORY', 1024));
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if (empty($value)) {
            return false;
        }

        if (!\is_numeric($value)) {
            return false;
        }

        if (!\in_array($value, self::filterBelowThreshold(MEMORY_VALUES, System::getEnv('_APP_FUNCTIONS_MEMORY', 1024)))) {
            return false;
        }

        return true;
    }

    /**
     * Is array.
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
     * Get Type.
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_INTEGER;
    }
}
