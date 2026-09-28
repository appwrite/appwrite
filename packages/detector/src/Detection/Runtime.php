<?php

namespace Utopia\Detector\Detection;

use Utopia\Detector\Detection;

abstract class Runtime extends Detection
{
    protected string $packager = '';

    public function __construct()
    {
    }

    public function setPackager(string $packager): self
    {
        $this->packager = $packager;

        return $this;
    }

    abstract public function getName(): string;

    /**
     * @return array<string> Programming languages used by the runtime.
     */
    abstract public function getLanguages(): array;

    /**
     * @return array<string> List of file extensions associated with the runtime.
     */
    abstract public function getFileExtensions(): array;

    /**
     * @return array<string> List of relevant files for detection.
     */
    abstract public function getFiles(): array;

    abstract public function getCommands(): string;

    abstract public function getEntrypoint(): string;
}
