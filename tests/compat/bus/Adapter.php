<?php

namespace Tests\Compat\Bus;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Bus\Bus;
use Utopia\Bus\Event;
use Utopia\Bus\Listener;
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory;

final class Deployed implements Event
{
}

final class Cancelled implements Event
{
}

final class Created implements Event
{
}

/**
 * A listener configured per instance: the dependencies it injects and what
 * its callback does (`record` its call, or `throw` {message, code}), or no
 * callback at all. Its name and events are its class's.
 */
abstract class Configured extends Listener
{
    /** @var list<array{string, string, list<mixed>}> Calls of every listener in the current dispatch. */
    public static array $calls = [];

    /**
     * @param list<string> $injections
     * @param array<string, mixed>|null $behaviour
     */
    public function __construct(array $injections, ?array $behaviour)
    {
        foreach ($injections as $name) {
            $this->inject($name);
        }
        if ($behaviour !== null) {
            $this->callback(function (Event $event, mixed ...$deps) use ($behaviour) {
                self::$calls[] = [static::getName(), $event::class, $deps];
                if (isset($behaviour['throw'])) {
                    throw new \RuntimeException($behaviour['throw']['message'], $behaviour['throw']['code']);
                }
            });
        }
    }
}

final class Clock extends Configured
{
    public static function getName(): string
    {
        return 'clock';
    }

    public static function getEvents(): array
    {
        return [Deployed::class];
    }
}

final class Mailer extends Configured
{
    public static function getName(): string
    {
        return 'mailer';
    }

    public static function getEvents(): array
    {
        return [Deployed::class];
    }
}

final class Refunds extends Configured
{
    public static function getName(): string
    {
        return 'refunds';
    }

    public static function getEvents(): array
    {
        return [Cancelled::class];
    }
}

final class Audit extends Configured
{
    public static function getName(): string
    {
        return 'audit';
    }

    public static function getEvents(): array
    {
        return [Deployed::class, Cancelled::class, Created::class];
    }
}

final class Twice extends Configured
{
    public static function getName(): string
    {
        return 'twice';
    }

    public static function getEvents(): array
    {
        return [Created::class, Created::class];
    }
}

/**
 * Maps tests/compat/bus/spec.json operations onto utopia-php/bus. Glue only: no logic.
 */
final class Adapter implements Base, Benchmarks
{
    private const LISTENERS = ['clock' => Clock::class, 'mailer' => Mailer::class, 'refunds' => Refunds::class, 'audit' => Audit::class, 'twice' => Twice::class];

    private const EVENTS = ['deployed' => Deployed::class, 'cancelled' => Cancelled::class, 'created' => Created::class];

    public function benchmarks(): array
    {
        return [
            'dispatch' => ['Dispatch an event to three listeners with one dependency each', function (Session $s) {
                $bus = (new Bus())->setResolver(fn (string $name) => $name);
                foreach ([Clock::class, Mailer::class, Audit::class] as $class) {
                    $bus->subscribe(new class (['db']) extends Configured {
                        public static function getName(): string
                        {
                            return 'bench';
                        }

                        public static function getEvents(): array
                        {
                            return [Deployed::class];
                        }

                        public function __construct(array $injections)
                        {
                            foreach ($injections as $name) {
                                $this->inject($name);
                            }
                            $this->callback(fn (Event $event, string $db) => null);
                        }
                    });
                }
                $event = new Deployed();

                return fn () => $bus->dispatch($event);
            }],
            'miss' => ['Dispatch an event no listener handles', function (Session $s) {
                $bus = (new Bus())->setResolver(fn (string $name) => $name)->subscribe(new Clock([], ['record' => true]));
                $event = new Created();

                return fn () => $bus->dispatch($event);
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'bus.dispatch' => fn (array $a, Session $s) => self::dispatch($a),
            'listener.inspect' => function (array $a, Session $s) {
                $listener = self::listener($a);
                try {
                    $callback = $listener->getCallback() instanceof \Closure;
                } catch (\LogicException $e) {
                    $callback = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
                }

                return ['injections' => $listener->getInjections(), 'callback' => $callback];
            },
        ];
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function listener(array $a): Configured
    {
        $class = self::LISTENERS[$a['class'] ?? ''] ?? throw new Fault('unknown listener ' . json_encode($a['class'] ?? null));
        $behaviour = $a['behaviour'] ?? null;

        return new $class(array_map(strval(...), $a['injections'] ?? []), \is_array($behaviour) ? $behaviour : null);
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function dispatch(array $a): array
    {
        Span::setStorage(new Memory());
        $span = Span::init('compat');
        Configured::$calls = [];
        $bus = new Bus();
        $resolver = $a['resolver'] ?? 'suffix';
        match (\is_array($resolver) ? 'fail' : $resolver) {
            'none' => null,
            'suffix' => $bus->setResolver(fn (string $name) => $name . ':resolved'),
            'fail' => $bus->setResolver(fn (string $name) => \in_array($name, $resolver['fail'], true) ? throw new \Exception("cannot resolve {$name}") : $name),
            default => throw new Fault('unknown resolver'),
        };
        foreach ($a['listeners'] ?? [] as $listener) {
            $bus->subscribe(self::listener($listener));
        }
        $error = null;
        foreach ($a['events'] ?? [] as $event) {
            $class = self::EVENTS[$event] ?? throw new Fault("unknown event {$event}");
            try {
                $bus->dispatch(new $class());
            } catch (\Throwable $e) {
                $error = ['class' => $e::class, 'message' => $e->getMessage()];
                break;
            }
        }
        $attributes = [];
        foreach ($span->getAttributes() as $key => $value) {
            // listener.<name>.<attribute>, grouped by listener.
            if (str_starts_with((string) $key, 'listener.')) {
                [, $name, $attribute] = explode('.', (string) $key, 3);
                $attributes[$name][$attribute] = $value;
            }
        }
        Span::setStorage(null);

        return [
            'calls' => Configured::$calls,
            'error' => $error,
            'span' => $attributes,
        ];
    }
}
