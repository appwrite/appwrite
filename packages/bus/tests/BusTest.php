<?php

namespace Utopia\Bus\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Bus\Event;
use Utopia\Bus\Listener;

class BusTest extends TestCase
{
    public function testDispatchCallsEverySubscribedListenerWithResolvedInjections(): void
    {
        $event = new class () implements Event {
        };
        $calls = [];
        $listener = new class ($calls, $event::class) extends Listener {
            public static string $event = '';

            public function __construct(array &$calls, string $event)
            {
                self::$event = $event;
                $this
                    ->desc('records what it receives')
                    ->inject('clock')
                    ->callback(function (Event $event, string $clock) use (&$calls) {
                        $calls[] = [$event::class, $clock];
                    });
            }

            public static function getName(): string
            {
                return 'recorder';
            }

            public static function getEvents(): array
            {
                return [self::$event];
            }
        };

        $bus = (new Bus())
            ->setResolver(fn (string $name) => $name . ':resolved')
            ->subscribe($listener)
            ->subscribe($listener);

        $bus->dispatch($event);

        $this->assertSame([[$event::class, 'clock:resolved'], [$event::class, 'clock:resolved']], $calls);
    }

    public function testDispatchWithoutResolverIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        (new Bus())->dispatch(new class () implements Event {
        });
    }
}
