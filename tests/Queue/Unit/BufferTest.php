<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Queue\Internal\Buffer;

final class BufferTest extends TestCase
{
    public function testCoalescesPendingRequestsAndPreservesIndependentResults(): void
    {
        $groups = $results = [];
        Coroutine\run(function () use (&$groups, &$results): void {
            $buffer = new Buffer(function (array $requests, callable $resolved) use (&$groups): void {
                $groups[] = \count($requests);
                Coroutine::sleep(0.01);
                foreach ($requests as $index => $n) {
                    $resolved($index, $n === 3 ? new \RuntimeException('uncertain') : $n);
                }
            });
            foreach (range(1, 100) as $n) {
                Coroutine::create(function () use ($buffer, $n, &$results): void {
                    try {
                        $results[$n] = $buffer->request($n);
                    } catch (\RuntimeException $error) {
                        $results[$n] = $error->getMessage();
                    }
                });
            }
        });
        $this->assertSame(100, array_sum($groups));
        $this->assertLessThan(100, \count($groups), 'Waiting requests should coalesce');
        $this->assertLessThanOrEqual(1000, max($groups));
        ksort($results);
        $expected = array_combine(range(1, 100), range(1, 100));
        $expected[3] = 'uncertain';
        $this->assertSame($expected, $results);
        $this->assertCount(100, $results);
        $this->assertSame('uncertain', $results[3]);
        $this->assertSame(100, $results[100]);
    }

    public function testConfirmedResultsSurviveLaterTransportFailure(): void
    {
        $results = [];
        $firstReturnedBeforeFailure = false;
        Coroutine\run(function () use (&$results, &$firstReturnedBeforeFailure): void {
            $buffer = new Buffer(function (array $requests, callable $resolved) use (&$results, &$firstReturnedBeforeFailure): void {
                Coroutine::sleep(0.001);
                foreach ($requests as $index => $request) {
                    if ($request === 3) {
                        $firstReturnedBeforeFailure = ($results[2] ?? null) === 2;
                        throw new \RuntimeException('connection lost');
                    }
                    $resolved($index, $request);
                    Coroutine::sleep(0.001);
                }
            });
            foreach (range(1, 4) as $request) {
                Coroutine::create(function () use ($buffer, $request, &$results): void {
                    try {
                        $results[$request] = $buffer->request($request);
                    } catch (\RuntimeException $error) {
                        $results[$request] = $error->getMessage();
                    }
                });
            }
        });
        ksort($results);
        $this->assertTrue($firstReturnedBeforeFailure);
        $this->assertSame([1 => 1, 2 => 2, 3 => 'connection lost', 4 => 'connection lost'], $results);
    }

    public function testMissingResultFailsSynchronousRequest(): void
    {
        $buffer = new Buffer(static function (array $requests, callable $resolved): void {});
        $this->expectExceptionMessage('Missing transport result');
        $buffer->request('unconfirmed');
    }

}
