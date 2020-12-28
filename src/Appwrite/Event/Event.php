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
     * @param string $queue
     * return $this
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * @return string
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * @param string $class
     * return $this
     */
    public function setClass(string $class): self
    {
        $this->class = $class;
        return $this;
    }

    /**
     * @return string
     */
    public function getClass()
    {
        return $this->class;
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
