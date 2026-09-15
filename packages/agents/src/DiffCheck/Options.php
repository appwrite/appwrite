<?php

namespace Utopia\Agents\DiffCheck;

use Utopia\Agents\Schema;

class Options
{
    protected int $maxDiffLines = 500;

    /**
     * @var array<int, string>
     */
    protected array $excludePaths = [];

    protected bool $ignoreAllSpace = true;

    protected bool $ignoreBlankLines = true;

    protected ?Schema $schema = null;

    /**
     * @var array<string, string>
     */
    protected array $instructions = [];

    protected string $description = '';

    protected string $userId = 'diff-check-user';

    protected bool $trimResponse = true;

    public function getMaxDiffLines(): int
    {
        return $this->maxDiffLines;
    }

    public function setMaxDiffLines(int $maxDiffLines): self
    {
        if ($maxDiffLines < 1) {
            throw new \InvalidArgumentException('maxDiffLines must be greater than 0');
        }

        $this->maxDiffLines = $maxDiffLines;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getExcludePaths(): array
    {
        return $this->excludePaths;
    }

    /**
     * @param  array<int, string>  $excludePaths
     */
    public function setExcludePaths(array $excludePaths): self
    {
        $this->excludePaths = array_values(array_filter(array_map('trim', $excludePaths), function ($path) {
            return $path !== '';
        }));

        return $this;
    }

    public function addExcludePath(string $excludePath): self
    {
        $excludePath = trim($excludePath);
        if ($excludePath !== '') {
            $this->excludePaths[] = $excludePath;
        }

        return $this;
    }

    public function getIgnoreAllSpace(): bool
    {
        return $this->ignoreAllSpace;
    }

    public function setIgnoreAllSpace(bool $ignoreAllSpace): self
    {
        $this->ignoreAllSpace = $ignoreAllSpace;

        return $this;
    }

    public function getIgnoreBlankLines(): bool
    {
        return $this->ignoreBlankLines;
    }

    public function setIgnoreBlankLines(bool $ignoreBlankLines): self
    {
        $this->ignoreBlankLines = $ignoreBlankLines;

        return $this;
    }

    public function getSchema(): ?Schema
    {
        return $this->schema;
    }

    public function setSchema(?Schema $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    /**
     * @param  array<string, string>  $instructions
     */
    public function setInstructions(array $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function addInstruction(string $name, string $content): self
    {
        $this->instructions[$name] = $content;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): self
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty');
        }

        $this->userId = $userId;

        return $this;
    }

    public function getTrimResponse(): bool
    {
        return $this->trimResponse;
    }

    public function setTrimResponse(bool $trimResponse): self
    {
        $this->trimResponse = $trimResponse;

        return $this;
    }
}
