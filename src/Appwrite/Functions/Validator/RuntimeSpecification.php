<?php

namespace Appwrite\Functions\Validator;

use Utopia\Config\Config;
use Utopia\System\System;
use Utopia\Validator;

class RuntimeSpecification extends Validator
{
    private array $plan;

    public function __construct(array $plan)
    {
        $this->plan = $plan;
    }

    /**
     * Get Allowed Specifications.
     *
     * Get allowed specifications taking into account the limits set by the environment variables and the plan.
     *
     * @return array
     */
    public function getAllowedSpecifications(): array
    {
        $specifications = Config::getParam('runtime-specifications', []);

        $allowedSpecficiations = [];

        foreach ($specifications as $size => $values) {
            if ($values['cpus'] <= System::getEnv('_APP_FUNCTIONS_CPUS', 1) && $values['memory'] <= System::getEnv('_APP_FUNCTIONS_MEMORY', 512)) {
                if (!empty($this->plan) && key_exists('runtimeSpecifications', $this->plan)) {
                    if (!\in_array($size, $this->plan['runtimeSpecifications'])) {
                        continue;
                    }
                }

                $allowedSpecifications[] = $size;
            }
        }

        return $allowedSpecifications;
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
        return 'String must be a valid specification value of ' . implode(', ', $this->getAllowedSpecifications());
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

        if (!\in_array($value, $this->getAllowedSpecifications())) {
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
