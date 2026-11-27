<?php

declare(strict_types=1);

namespace Tests\Unit\Promises;

use Appwrite\Promises\Swoole;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

final class SwooleTest extends TestCase
{
    /**
     * A GraphQL batch returns its results in this order, so it must be the order of the
     * promises, not the order they complete in.
     */
    #[RunInSeparateProcess]
    public function testAllResolvesInTheOrderOfThePromises(): void
    {
        $values = null;

        run(function () use (&$values): void {
            $slow = new Swoole(function (callable $resolve): void {
                Coroutine::sleep(0.05);
                $resolve('first');
            });
            $fast = new Swoole(function (callable $resolve): void {
                $resolve('second');
            });

            Swoole::all([$slow, $fast])->then(function (array $result) use (&$values): void {
                $values = $result;
            });
        });

        // Identical arrays have the same keys in the same order, so this is a JSON list
        $this->assertSame(['first', 'second'], $values);
    }
}
