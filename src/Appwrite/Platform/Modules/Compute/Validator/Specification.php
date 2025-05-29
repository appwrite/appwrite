<?php

namespace Appwrite\Platform\Modules\Compute\Validator;

use Utopia\Validator;

class Specification extends Validator
{
    private array $plan;

    private array $specifications;

    private float $maxCpus;

    private int $maxMemory;

    public function __construct(array $plan, array $specifications, float $maxCpus, int $maxMemory)
    {
        $this->plan = $plan;
        $this->specifications = $specifications;
        $this->maxCpus = $maxCpus;
        $this->maxMemory = $maxMemory;
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
        $allowedSpecifications = [];

        foreach ($this->specifications as $size => $values) {
            if ((empty($this->maxCpus) || $values['cpus'] <= $this->maxCpus) && (empty($this->maxMemory) || $values['memory'] <= $this->maxMemory)) {
                if (!empty($this->plan) && array_key_exists('runtimeSpecifications', $this->plan)) {
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
        return 'Specification must be one of: ' . implode(', ', $this->getAllowedSpecifications());
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
