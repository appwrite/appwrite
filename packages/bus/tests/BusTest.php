<?php

namespace Utopia\Bus\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Bus\Event;
use Utopia\Bus\Listener;

class BusTest extends TestCase
{
    public function testDispatchCallsEachSubscribedListenerWithItsOwnInjections(): void
    {
        $event = new class () implements Event {
        };
        $calls = [];

        $bus = (new Bus())
            ->setResolver(fn (string $name) => $name . ':resolved')
            ->subscribe($this->listener('first', $event::class, 'clock', $calls))
            ->subscribe($this->listener('second', $event::class, 'mailer', $calls));

        $bus->dispatch($event);

        $this->assertSame([
            ['first', $event::class, 'clock:resolved'],
            ['second', $event::class, 'mailer:resolved'],
        ], $calls);
    }

    public function testDispatchSkipsListenersOfOtherEvents(): void
    {
        $dispatched = new class () implements Event {
        };
        $other = new class () implements Event {
        };
        $calls = [];

        $bus = (new Bus())
            ->setResolver(fn (string $name) => $name)
            ->subscribe($this->listener('interested', $dispatched::class, 'dep', $calls))
            ->subscribe($this->listener('uninterested', $other::class, 'dep', $calls));

        $bus->dispatch($dispatched);

        $this->assertSame([['interested', $dispatched::class, 'dep']], $calls);
    }

    public function testDispatchWithoutResolverIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        (new Bus())->dispatch(new class () implements Event {
        });
    }

    /**
     * @param array<array{string, string, string}> $calls
     */
    private function listener(string $name, string $event, string $injection, array &$calls): Listener
    {
        return new class ($name, $event, $injection, $calls) extends Listener {
            private static string $name = '';
            private static string $event = '';

            public function __construct(string $name, string $event, string $injection, array &$calls)
            {
                self::$name = $name;
                self::$event = $event;
                $this
                    ->inject($injection)
                    ->callback(function (Event $received, string $dependency) use ($name, &$calls) {
                        $calls[] = [$name, $received::class, $dependency];
                    });
            }

            public static function getName(): string
            {
                return self::$name;
            }

            public static function getEvents(): array
            {
                return [self::$event];
            }
        };
    }
}
