<?php

namespace Utopia\Agents\DiffCheck;

class Repository
{
    protected string $source;

    protected bool $remote;

    protected ?string $ref;

    public function __construct(string $source, bool $remote = false, ?string $ref = null)
    {
        $source = trim($source);
        if ($source === '') {
            throw new \InvalidArgumentException('Repository source must not be empty');
        }

        $this->source = $source;
        $this->remote = $remote;
        $this->ref = $ref;
    }

    public static function local(string $path, ?string $ref = null): self
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new \InvalidArgumentException('Local repository path does not exist: '.$path);
        }

        return new self($resolved, false, $ref);
    }

    public static function remote(string $url, ?string $ref = null): self
    {
        return new self($url, true, $ref);
    }

    public static function from(string|self $repository): self
    {
        if ($repository instanceof self) {
            return $repository;
        }

        $value = trim($repository);
        if ($value === '') {
            throw new \InvalidArgumentException('Repository source must not be empty');
        }

        if (file_exists($value)) {
            return self::local($value);
        }

        if (self::looksLikeRemote($value)) {
            return self::remote($value);
        }

        throw new \InvalidArgumentException('Repository source is not a valid local path or remote URL: '.$value);
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isRemote(): bool
    {
        return $this->remote;
    }

    public function getRef(): ?string
    {
        return $this->ref;
    }

    protected static function looksLikeRemote(string $value): bool
    {
        if (preg_match('/^(https?:\/\/|ssh:\/\/|git:\/\/|git@|file:\/\/)/', $value) === 1) {
            return true;
        }

        // git@github.com:org/repo.git
        if (preg_match('/^[^@\s]+@[^:\s]+:.+$/', $value) === 1) {
            return true;
        }

        return false;
    }
}
