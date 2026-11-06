<?php

namespace Utopia\Detector\Detection\Framework;

use Utopia\Detector\Detection\Framework;

abstract class JS extends Framework
{
    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        // Do not score a bare package.json. Every JS framework inherits this
        // base, so treating the lockfile manifest as a hit forces a multi-way
        // tie (all score 1) that collapses to Angular via fewest-parents —
        // even for lodash-only or tooling-only repos. Frameworks must match
        // their own config files and/or dependency packages instead.
        return [];
    }
}
