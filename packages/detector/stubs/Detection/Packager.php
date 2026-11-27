<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detection;

use Utopia\Detector\Detection;

abstract class Packager extends Detection
{
    abstract public function getName(): string;

    /**
     * @return array<string> List of relevant files for detection.
     */
    abstract public function getFiles(): array;
}
