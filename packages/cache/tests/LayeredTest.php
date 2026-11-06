<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use Utopia\Cache\Adapter\Bounded;
use Utopia\Cache\Adapter\Layered;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

final class LayeredTest extends Base
{
    private const int LIMIT = 8;

    private Bounded $front;

    private Memory $back;

    private Layered $layered;

    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new Layered(new Bounded(1024, 1024), new Memory(), 'project', 60));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->front = new Bounded(32, self::LIMIT);
        $this->back = new Memory();
        $this->layered = new Layered($this->front, $this->back, 'project', 60);
    }

    public function testASmallReadIsAnsweredFromTheFrontWithoutTheBack(): void
    {
        $this->back->save('key', 'small');
        $this->assertSame('small', $this->layered->load('key', 60));

        $this->back->purge('key');

        $this->assertSame('small', $this->layered->load('key', 60));
    }

    public function testALargeReadStaysOnlyInTheBack(): void
    {
        $this->back->save('key', 'over-the-limit');
        $this->assertSame('over-the-limit', $this->layered->load('key', 60));

        $this->back->purge('key');

        $this->assertFalse($this->layered->load('key', 60));
    }

    public function testAMissIsNotRemembered(): void
    {
        $this->assertFalse($this->layered->load('key', 60));

        $this->back->save('key', 'small');

        $this->assertSame('small', $this->layered->load('key', 60));
    }

    public function testScopesDoNotShareEntries(): void
    {
        $other = new Layered($this->front, new Memory(), 'other', 60);

        $this->layered->save('key', 'mine');

        $this->assertFalse($other->load('key', 60));
        $this->assertSame('mine', $this->layered->load('key', 60));
    }

    public function testTheFrontServesAnEntryOnlyForItsOwnTtl(): void
    {
        $layered = new Layered($this->front, $this->back, 'project', 0);

        $layered->save('key', 'old');
        $this->back->save('key', 'new');

        $this->assertSame('new', $layered->load('key', 60));
    }

    public function testACallersShorterTtlAppliesToTheFront(): void
    {
        $this->layered->save('key', 'small');
        $this->back->purge('key');

        $this->assertFalse($this->layered->load('key', 0));
    }

    public function testAPurgeClearsBothLayers(): void
    {
        $this->layered->save('key', 'small');
        $this->assertTrue($this->layered->purge('key'));

        $this->assertFalse($this->layered->load('key', 60));
        $this->assertFalse($this->back->load('key', 60));
    }

    public function testAFailedBackSaveDoesNotLeaveAnOldValueInTheFront(): void
    {
        $this->layered->save('key', 'old');

        $this->assertFalse($this->layered->save('key', ''));
        $this->back->purge('key');

        $this->assertFalse($this->layered->load('key', 60));
    }

    public function testTheLeastRecentlyUsedEntryGoesFirst(): void
    {
        $bounded = new Bounded(12, 8);

        $bounded->save('a', '12345');
        $bounded->save('b', '12345');
        $bounded->load('a', 60);
        $bounded->save('c', '12345');

        $this->assertSame('12345', $bounded->load('a', 60));
        $this->assertFalse($bounded->load('b', 60));
        $this->assertSame('12345', $bounded->load('c', 60));
        $this->assertSame(12, $bounded->getSize());
    }

    public function testReplacingAnEntryWithOneTooLargeDropsTheOldValue(): void
    {
        $bounded = new Bounded(32, 4);

        $bounded->save('a', '1234');
        $this->assertFalse($bounded->save('a', '12345'));

        $this->assertFalse($bounded->load('a', 60));
        $this->assertSame(0, $bounded->getSize());
    }
}
