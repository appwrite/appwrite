<?php

declare(strict_types=1);

namespace Utopia\Auth;

abstract class Hash
{
    /**
     * @var array<string, mixed> Hash-specific options
     */
    protected array $options = [];

    /**
     * Set hashing options
     *
     * @param  string  $key  The option key to set
     * @param  mixed  $value  The value to set for the option
     */
    public function setOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Set multiple hashing options at once
     *
     * @param  array<string, mixed>  $options  Array of options to set
     */
    public function setOptions(array $options): static
    {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }

        return $this;
    }

    /**
     * Get a specific option value
     *
     * @param  string  $key  The option key to retrieve
     * @param  mixed  $default  Default value if option doesn't exist
     * @return mixed The option value or default if not found
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Get hashing options
     *
     * @return array<string, mixed> Hash-specific options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Hash a value
     */
    abstract public function hash(string $value): string;

    /**
     * Verify a value against a hash
     */
    abstract public function verify(string $value, string $hash): bool;

    /**
     * Get the name of the hash algorithm
     */
    abstract public function getName(): string;
}
