<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Broker\Pool;
use Utopia\Queue\Publisher\Outcome;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

final class PoolTest extends TestCase
{
    private int $created = 0;

    /**
     * @return array<string, array{Outcome}>
     */
    public static function outcomes(): array
    {
        return [
            'published' => [Outcome::Published],
            'coalesced' => [Outcome::Coalesced],
        ];
    }

    #[DataProvider('outcomes')]
    public function testCoalesceReturnsThePooledPublishersOutcome(Outcome $outcome): void
    {
        $publisher = new CoalescingPublisher($outcome);
        $pool = new Pool($this->pool($publisher));

        $result = $pool->coalesce(new Queue('stats'), ['projectId' => 'p1'], 'p1');

        $this->assertSame($outcome, $result);
        $this->assertSame([['queue' => 'stats', 'payload' => ['projectId' => 'p1'], 'key' => 'p1']], $publisher->coalesced);
    }

    public function testCoalesceThrowsWhenThePooledPublisherCannotCoalesce(): void
    {
        $pool = new Pool($this->pool(new SynchronousPublisher()));

        $this->expectException(\LogicException::class);

        $pool->coalesce(new Queue('stats'), ['projectId' => 'p1'], 'p1');
    }

    public function testCoalesceThrowsWithoutAPublisherPool(): void
    {
        $pool = new Pool(consumer: $this->pool(new CoalescingPublisher(Outcome::Published)));

        $this->expectException(\LogicException::class);

        $pool->coalesce(new Queue('stats'), ['projectId' => 'p1'], 'p1');
    }

    public function testCoalesceRejectsAnEmptyKeyBeforeLeasing(): void
    {
        $publisher = new CoalescingPublisher(Outcome::Published);
        $pool = new Pool($this->pool($publisher));

        try {
            $pool->coalesce(new Queue('stats'), ['projectId' => 'p1'], '');
            $this->fail('An empty key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Cannot coalesce with an empty key.', $exception->getMessage());
        }

        $this->assertSame(0, $this->created);
        $this->assertSame([], $publisher->coalesced);
    }

    /**
     * @return UtopiaPool<Synchronous>
     */
    private function pool(Synchronous $publisher): UtopiaPool
    {
        return new UtopiaPool(new Stack(), 'test', 1, function () use ($publisher): Synchronous {
            $this->created++;

            return $publisher;
        }, timeout: 0.0);
    }
}
