<?php

namespace Appwrite\Functions\Validator;

use Utopia\Config\Config;
use Utopia\System\System;
use Utopia\Validator;

class RuntimeSize extends Validator
{
    private array $plan;

    public function __construct(array $plan)
    {
        $this->plan = $plan;
    }

    /**
     * Get Allowed Values.
     *
     * Get allowed values taking into account the limits set by the environment variables.
     *
     * @return array
     */
    public function getAllowedSizes(): array
    {
        $sizes = Config::getParam('runtime-sizes', []);

        $allowedSizes = [];

        foreach ($sizes as $size => $values) {
            if ($values['cpus'] <= System::getEnv('_APP_FUNCTIONS_CPUS', 1) && $values['memory'] <= System::getEnv('_APP_FUNCTIONS_MEMORY', 512)) {
                if (!empty($this->plan) && key_exists('runtimeSizes', $this->plan)) {
                    if (!\in_array($size, $this->plan['runtimeSizes'])) {
                        continue;
                    }
                }

                $allowedSizes[] = $size;
            }
        }

        return $allowedSizes;
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
        return 'String must be a valid size value of ' . implode(', ', $this->getAllowedSizes());
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

        if (!\is_string($value)) {
            return false;
        }

        if (!\in_array($value, $this->getAllowedSizes())) {
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
        return self::TYPE_STRING;
    }
}
