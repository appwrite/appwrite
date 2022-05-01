<?php

namespace Appwrite\Auth;

abstract class Hash
{
    /**
     * @var mixed $options Hashing-algo specific options
    */
    protected mixed $options = [];
    
    /**
     * @param mixed $options Hashing-algo specific options
    */
    public function __construct(mixed $options = []) {
        $this->setOptions($options);
    }

    /**
     * Set hashing algo options
     * 
     * @param mixed $options Hashing-algo specific options
    */
    public function setOptions(mixed $options): self {
        $this->options = \array_merge([], $this->getDefaultOptions(), $options);
        return $this;
    }

    /**
     * Get hashing algo options
     * 
     * @return mixed $options Hashing-algo specific options
    */
    public function getOptions(): mixed {
        return $this->options;
    }

    /**
     * @param string $password Input password to hash
     * 
     * @return string hash
     */
    abstract public function hash(string $password): string;

    /**
     * @param string $password Input password to validate
     * @param string $hash Hash to verify password against
     * 
     * @return boolean true if password matches hash
     */
    abstract public function verify(string $password, string $hash): bool;

    /**
     * Get default options for specific hashing algo
     * 
     * @return mixed options named array
     */
    abstract public function getDefaultOptions(): mixed;
}
