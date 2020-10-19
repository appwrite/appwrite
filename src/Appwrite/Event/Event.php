<?php

namespace Appwrite\Event;

use Resque;

class Event
{
    /**
     * @var string
     */
    protected $queue = '';

    /**
     * @var string
     */
    protected $class = '';

    /**
     * @var array
     */
    protected $params = [];

    /**
     * Event constructor.
     *
     * @param string $queue
     * @param string $class
     */
    public function __construct(string $queue, string $class)
    {
        $this->queue = $queue;
        $this->class = $class;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function setParam(string $key, $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     *
     * @return mixed|null
     */
    public function getParam(string $key)
    {
        return (isset($this->params[$key])) ? $this->params[$key] : null;
    }

    /**
     * Execute Event.
     */
    public function trigger(): void
    {
        Resque::enqueue($this->queue, $this->class, $this->params);

        $this->reset();
    }

    public function reset(): self
    {
        $this->params = [];

        return $this;
    }
}
