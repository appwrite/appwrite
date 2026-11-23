<?php

namespace Utopia\Detector\Detection;

use Utopia\Detector\Detection;

abstract class Rendering extends Detection
{
    public function __construct(protected ?string $fallbackFile = null)
    {
        $this->fallbackFile = $fallbackFile;
    }

    public function getFallbackFile(): ?string
    {
        return $this->fallbackFile;
    }

    abstract public function getName(): string;

    /**
     * @return array<string> List of relevant files for detection.
     */
    abstract public function getFiles(string $framework): array;
}
