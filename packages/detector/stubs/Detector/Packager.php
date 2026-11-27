<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Packager as PackagerDetection;
use Utopia\Detector\Detector;

class Packager extends Detector
{
    /**
     * @var array<PackagerDetection>
     */
    protected array $options = [];

    public function __construct()
    {
    }

    public function detect(): ?PackagerDetection
    {
    }
}
