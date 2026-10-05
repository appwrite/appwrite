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
        $calls = [];

        $bus = (new Bus())
            ->setResolver(fn (string $name) => $name . ':resolved')
            ->subscribe(new ClockListener($calls))
            ->subscribe(new MailerListener($calls));

        $bus->dispatch(new Deployed());

        $this->assertEqualsCanonicalizing([
            ['clock', Deployed::class, 'clock:resolved'],
            ['mailer', Deployed::class, 'mailer:resolved'],
        ], $calls);
    }

    public function testDispatchSkipsListenersOfOtherEvents(): void
    {
        $calls = [];

        $bus = (new Bus())
            ->setResolver(fn (string $name) => $name)
            ->subscribe(new ClockListener($calls))
            ->subscribe(new CancelledListener($calls));

        $bus->dispatch(new Deployed());

        $this->assertSame([['clock', Deployed::class, 'clock']], $calls);
    }

    public function testDispatchWithoutResolverIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        (new Bus())->dispatch(new Deployed());
    }
}

final class Deployed implements Event
{
}

final class Cancelled implements Event
{
}

abstract class Recording extends Listener
{
    /**
     * @param array<array{string, string, string}> $calls
     */
    public function __construct(array &$calls)
    {
        $this
            ->inject(static::getName())
            ->callback(function (Event $event, string $dependency) use (&$calls) {
                $calls[] = [static::getName(), $event::class, $dependency];
            });
    }
}

final class ClockListener extends Recording
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

final class MailerListener extends Recording
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

final class CancelledListener extends Recording
{
    public static function getName(): string
    {
        return 'cancelled';
    }

    public static function getEvents(): array
    {
        return [Cancelled::class];
    }
}
