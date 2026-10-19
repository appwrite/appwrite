<?php

namespace Appwrite\Maintenance;

/**
 * A tick boundary the loop woke for, and how many boundaries before it were skipped because sweeps overran.
 */
final readonly class Tick
{
    public function __construct(
        public int $boundary,
        public int $skipped,
    ) {
    }
}
