<?php

namespace Utopia\Bus;

use Utopia\Span\Span;

class Bus
{
    /** @var array<class-string<Event>, Listener[]> */
    private array $listeners = [];

    /** @var ?\Closure(string): mixed */
    private ?\Closure $resolver = null;

    public function setResolver(callable $resolver): self
    {
        $this->resolver = $resolver(...);
        return $this;
    }

    public function subscribe(Listener $listener): self
    {
        foreach ($listener::getEvents() as $event) {
            $this->listeners[$event][] = $listener;
        }
        return $this;
    }

    public function dispatch(Event $event): void
    {
        if ($this->resolver === null) {
            throw new \LogicException('Bus resolver must be set via setResolver() before dispatching events');
        }

        $resolver = $this->resolver;
        $listeners = $this->listeners[$event::class] ?? [];

        foreach ($listeners as $listener) {
            $deps = array_map($resolver, $listener->getInjections());

            Span::current()?->add('listener.' . $listener::getName() . '.event', $event::class);

            try {
                ($listener->getCallback())($event, ...$deps);
                Span::current()?->add('listener.' . $listener::getName() . '.success', true);
            } catch (\Throwable $e) {
                Span::current()?->add('listener.' . $listener::getName() . '.success', false);
                Span::current()?->add('listener.' . $listener::getName() . '.error.code', $e->getCode());
                Span::current()?->add('listener.' . $listener::getName() . '.error.message', $e->getMessage());
                Span::current()?->add('listener.' . $listener::getName() . '.error.line', $e->getLine());
                Span::current()?->add('listener.' . $listener::getName() . '.error.file', $e->getFile());
                Span::current()?->add('listener.' . $listener::getName() . '.error.trace', $e->getTraceAsString());
            }
        }
    }
}
