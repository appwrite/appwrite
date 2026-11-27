<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Rendering as RenderingDetection;
use Utopia\Detector\Detector;

class Rendering extends Detector
{
    protected string $framework;

    /**
     * @var array<RenderingDetection>
     */
    protected array $options = [];

    public function __construct(string $framework)
    {
    }

    public function detect(): RenderingDetection
    {
    }
}
