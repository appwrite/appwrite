<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector;

abstract class Detector
{
    /**
     * @var array<Detection>
     */
    protected array $options = [];

    /**
     * @var array<array{type: string, content: string}>
     */
    protected array $inputs = [];

    public function __construct()
    {
    }

    /**
     * Add input with its type
     *
     * @param string $content Input content
     * @param string $type Input type (e.g., 'path', 'packages', 'extension', 'language', ..)
     */
    public function addInput(string $content, string $type = ''): self
    {
    }

    abstract public function detect(): ?Detection;

    public function addOption(Detection $option): self
    {
    }
}
