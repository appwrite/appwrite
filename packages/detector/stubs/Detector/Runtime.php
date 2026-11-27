<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Runtime as RuntimeDetection;
use Utopia\Detector\Detector;

class Runtime extends Detector
{
    /**
     * @var array<RuntimeDetection>
     */
    protected array $options = [];

    protected Strategy $strategy;

    protected string $packager = 'pnpm';

    public function __construct(Strategy $strategy, string $packager = 'pnpm')
    {
    }

    public function detect(): ?RuntimeDetection
    {
    }
}
