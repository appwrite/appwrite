<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\ScheduleExecutions;
use Appwrite\Platform\Tasks\ScheduleFunctions;
use Appwrite\Platform\Tasks\ScheduleMessages;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle the combined `schedule` task drives: it wires every scheduler,
 * bootstraps them one at a time so they do not contend for the shared console
 * and cache pools, then gives each its own coroutine. Drift in any of these
 * signatures would only surface when that container boots, so it is pinned
 * here.
 */
final class ScheduleLifecycleTest extends TestCase
{
    /**
     * @return \Iterator<int<0, max>, array{string}>
     */
    public static function taskProvider(): \Iterator
    {
        yield [ScheduleFunctions::class];
        yield [ScheduleExecutions::class];
        yield [ScheduleMessages::class];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('taskProvider')]
    public function testEachTaskExposesTheCombinedLifecycle(string $task): void
    {
        $expected = [
            'start' => 6,        // publisher, telemetry, dbForPlatform, getProjectDB, isResourceBlocked, pools
            'listen' => 0,
            'scheduleCount' => 0,
        ];

        foreach ($expected as $method => $arity) {
            $this->assertTrue(\method_exists($task, $method), "{$task}::{$method}() is missing");

            $reflected = new \ReflectionMethod($task, $method);
            $this->assertTrue($reflected->isPublic(), "{$task}::{$method}() must be callable by the combined task");
            $this->assertSame($arity, $reflected->getNumberOfRequiredParameters(), "{$task}::{$method}() arity changed");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('taskProvider')]
    public function testListeningBeforeBootstrappingRefuses(string $task): void
    {
        // Bootstrapping is what builds the scheduler, so listening first would
        // otherwise dereference null and take the whole container down.
        $this->expectException(\LogicException::class);

        (new $task())->listen();
    }

    public function testTheCombinedTaskStillOnlyNeedsThoseThreeMethods(): void
    {
        $source = \file_get_contents(__DIR__ . '/../../../../src/Appwrite/Platform/Tasks/Schedule.php');
        $this->assertIsString($source);

        \preg_match_all('/\$(?:functions|executions|messages|task)->(\w+)\(/', $source, $matches);
        $called = \array_values(\array_unique($matches[1]));
        \sort($called);

        $this->assertSame(['listen', 'scheduleCount', 'start'], $called);
    }

    /**
     * Scheduled executions must reach the queue in the order they were due.
     *
     * A coroutine per execution let a later one overtake an earlier one, and
     * that reordering is what #13270 fixed on main. The scheduler hands the
     * batch over oldest-first, so publishing inline preserves it — spawning
     * here would silently undo the fix, and the failure is a queue order no
     * unit test observes directly.
     */
    public function testScheduledExecutionsArePublishedInline(): void
    {
        $source = \file_get_contents(__DIR__ . '/../../../../src/Appwrite/Platform/Tasks/ScheduleExecutions.php');
        $this->assertIsString($source);

        $dispatch = \strstr($source, 'private function dispatch(');
        $this->assertIsString($dispatch, 'the dispatch is where publishing happens');
        $this->assertStringNotContainsString('\go(', $dispatch);
    }
}
