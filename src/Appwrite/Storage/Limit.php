<?php

namespace Appwrite\Storage;

use InvalidArgumentException;

/**
 * File size limits for a server, plan and bucket. Zero disables that limit.
 */
class Limit
{
    public function __construct(private int $server, private int $plan = 0)
    {
        if ($server < 0 || $plan < 0) {
            throw new InvalidArgumentException('File size limits must not be negative.');
        }
    }

    public function maximum(int $bucket = 0): int
    {
        if ($bucket < 0) {
            throw new InvalidArgumentException('File size limits must not be negative.');
        }

        $maximum = 0;
        foreach ([$this->server, $this->plan, $bucket] as $limit) {
            if ($limit > 0 && ($maximum === 0 || $limit < $maximum)) {
                $maximum = $limit;
            }
        }

        return $maximum;
    }

    public function allows(mixed $size, int $bucket = 0): bool
    {
        if (!\is_int($size) || $size < 0) {
            return false;
        }

        $maximum = $this->maximum($bucket);

        return $maximum === 0 || $size <= $maximum;
    }
}
