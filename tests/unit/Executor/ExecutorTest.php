<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use Executor\Executor;
use PHPUnit\Framework\TestCase;

final class ExecutorTest extends TestCase
{
    public function testRuntimeIdKeepsHyphenFreePairs(): void
    {
        $this->assertSame('6a1b2c3d-6e4f5a6b', Executor::runtimeId('6a1b2c3d', '6e4f5a6b'));
    }

    public function testRuntimeIdSeparatesHyphenatedPairs(): void
    {
        $this->assertNotSame(
            Executor::runtimeId('my-app', 'deployment'),
            Executor::runtimeId('my', 'app-deployment')
        );
    }

    public function testRuntimeIdOfHyphenatedPairDiffersFromHyphenFreePair(): void
    {
        $this->assertNotSame(Executor::runtimeId('my-app', '6'), Executor::runtimeId('my', 'app-6'));
        $this->assertNotSame(Executor::runtimeId('a-b', 'c'), Executor::runtimeId('a', 'b-c-1'));
    }

    public function testRuntimeIdKeepsProjectPrefix(): void
    {
        $this->assertStringStartsWith('my-app-', Executor::runtimeId('my-app', 'deployment'));
    }
}
