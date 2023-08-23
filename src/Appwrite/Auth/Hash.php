<?php

namespace Appwrite\Auth;

abstract class Hash
{
    /**
     * @var array Hashing-algo specific options
     */
    protected array $options = [];

    /**
     * @param  array  $options Hashing-algo specific options
     */
    public function __construct(array $options = [])
    {
        $this->setOptions($options);
    }

    /**
     * Set hashing algo options
     *
     * @param  array  $options Hashing-algo specific options
     */
    public function setOptions(array $options): self
    {
        $this->options = \array_merge([], $this->getDefaultOptions(), $options);

        return $this;
    }

    /**
     * Get hashing algo options
     *
     * @return array $options Hashing-algo specific options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param  string  $password Input password to hash
     * @return string hash
     */
    abstract public function hash(string $password): string;

    /**
     * @param  string  $password Input password to validate
     * @param  string  $hash Hash to verify password against
     * @return bool true if password matches hash
     */
    abstract public function verify(string $password, string $hash): bool;

    /**
     * Get default options for specific hashing algo
     *
     * @return array options named array
     */
    abstract public function getDefaultOptions(): array;
}
