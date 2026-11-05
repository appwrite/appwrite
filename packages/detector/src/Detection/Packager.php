<?php

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
