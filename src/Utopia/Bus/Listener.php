<?php

namespace Utopia\Bus;

abstract class Listener
{
    protected ?string $desc = null;
    /** @var array<string> */
    protected array $injections = [];
    protected ?\Closure $callback = null;

    abstract public static function getName(): string;

    /**
     * @return array<class-string<Event>>
     */
    abstract public static function getEvents(): array;

    protected function desc(string $desc): self
    {
        $this->desc = $desc;
        return $this;
    }

    protected function inject(string $injection): self
    {
        $this->injections[] = $injection;
        return $this;
    }

    protected function callback(callable $callback): self
    {
        $this->callback = $callback(...);
        return $this;
    }

    /** @return array<string> */
    public function getInjections(): array
    {
        return $this->injections;
    }

    public function getCallback(): callable
    {
        if ($this->callback === null) {
            throw new \LogicException(static::class . ' must set a callback via $this->callback()');
        }

        return $this->callback;
    }
}
