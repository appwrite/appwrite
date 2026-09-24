<?php

namespace Utopia\Registry;

use Exception;

class Registry
{
    /**
     * List of all callbacks
     *
     * @var callable[]
     */
    protected array $callbacks = [];

    /**
     * List of all fresh resources
     *
     * @var array<string, bool>
     */
    protected array $fresh = [];

    /**
     * List of all connections
     *
     * @var array<string, mixed>
     */
    protected array $registry = [
        'default' => [],
    ];

    /**
     * Current context
     *
     * @var string
     */
    protected string $context = 'default';

    /**
     * Set a new connection callback
     *
     * @param  string  $name
     * @param  callable  $callback
     * @param  bool  $fresh
     * @return $this
     *
     * @throws Exception
     */
    public function set(string $name, callable $callback, bool $fresh = false): self
    {
        if (\array_key_exists($name, $this->registry[$this->context])) {
            unset($this->registry[$this->context][$name]);
        }

        $this->fresh[$name] = $fresh;
        $this->callbacks[$name] = $callback;

        return $this;
    }

    /**
     * If connection has been created returns it, otherwise create and than return it
     *
     * @param  string  $name
     * @param  bool  $fresh
     * @return mixed
     *
     * @throws Exception
     */
    public function get(string $name, bool $fresh = false): mixed
    {
        if (! \array_key_exists($name, $this->registry[$this->context]) || $fresh || $this->fresh[$name]) {
            if (! \array_key_exists($name, $this->callbacks)) {
                throw new Exception('No callback named "'.$name.'" found when trying to create connection');
            }
            $this->registry[$this->context][$name] = $this->callbacks[$name]();
        }

        return $this->registry[$this->context][$name];
    }

    /**
     * Check if connection exists
     *
     * @param  string  $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->callbacks);
    }

    /**
     * Set the current context
     *
     * @param  string  $name
     * @return self
     */
    public function context(string $name): self
    {
        if (! array_key_exists($name, $this->registry)) {
            $this->registry[$name] = [];
        }

        $this->context = $name;

        return $this;
    }
}
