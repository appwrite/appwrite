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

        /** @var array<array{Listener, array<mixed>}> $resolved */
        $resolved = [];
        foreach ($listeners as $listener) {
            $deps = array_map($resolver, $listener->getInjections());
            $resolved[] = [$listener, $deps];
        }

        go(function () use ($resolved, $event) {
            foreach ($resolved as [$listener, $deps]) {
                $action = 'listener.' . $listener::getName();
                Span::init($action);
                Span::add('bus.event', $event::class);
                try {
                    ($listener->getCallback())($event, ...$deps);
                } catch (\Throwable $e) {
                    Span::error($e);
                } finally {
                    Span::current()?->finish();
                }
            }
        });
    }
}
