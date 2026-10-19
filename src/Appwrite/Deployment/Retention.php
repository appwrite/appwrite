<?php

namespace Appwrite\Deployment;

readonly class Retention
{
    public function __construct(
        public int $days = 0,
        public int $maxDays = 0,
        public int $maxCount = 0,
    ) {
        if ($days < 0 || $maxDays < 0 || $maxCount < 0) {
            throw new \InvalidArgumentException('Deployment retention values must not be negative.');
        }
    }

    /**
     * Days a resource keeps non-active deployments: the requested days, else the default, clamped to the maximum. 0 keeps them forever.
     */
    public function resolve(int $requested): int
    {
        $days = $requested > 0 ? $requested : $this->days;

        return $this->maxDays > 0 && ($days === 0 || $days > $this->maxDays) ? $this->maxDays : $days;
    }
}
