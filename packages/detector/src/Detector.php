<?php

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
        $this->inputs[] = [
            'type' => $type,
            'content' => $content,
        ];

        return $this;
    }

    abstract public function detect(): ?Detection;

    public function addOption(Detection $option): self
    {
        $this->options[] = $option;

        return $this;
    }
}
