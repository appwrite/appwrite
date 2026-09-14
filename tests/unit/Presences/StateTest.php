<?php

declare(strict_types=1);

namespace Tests\Unit\Presences;

use Appwrite\Event\Event;
use Appwrite\Event\Realtime;
use Appwrite\Presences\State;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Database\Document;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory;

final class StateTest extends TestCase
{
    public function testFailedPresenceEventRemainsOnTheCallerSpan(): void
    {
        $failure = new RuntimeException('Realtime transport unavailable');
        $queue = $this->getMockBuilder(Realtime::class)->onlyMethods(['trigger'])->getMock();
        $queue->expects($this->once())->method('trigger')->willThrowException($failure);
        Span::setStorage(new Memory());
        $span = Span::init('realtime.message');

        try {
            (new State())->triggerEvent(
                new Event($this->createStub(Synchronous::class)),
                $queue,
                new Document(['$id' => 'project-a']),
                new User(['$id' => 'user-a']),
                'presences.[presenceId].delete',
                new Document(['$id' => 'presence-a']),
            );

            $this->assertSame($span, Span::current());
            $this->assertSame($failure, $span->getError());
            $this->assertSame('project-a', $span->get('project.id'));
            $this->assertSame('presences.[presenceId].delete', $span->get('presence.event'));
            $this->assertNull($span->get('span.finished_at'));
        } finally {
            Span::setStorage(null);
        }
    }
}
