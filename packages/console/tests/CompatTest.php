<?php

declare(strict_types=1);

namespace Utopia\Console\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Utopia\Console\Command;
use Utopia\Console\Console;

final class CompatTest extends TestCase
{
    public function testOldConsoleNameResolvesToTheMovedClass(): void
    {
        $old = \Utopia\Console::class;
        if (!class_exists($old)) {
            $this->fail($old . ' does not resolve');
        }

        $this->assertSame(Console::class, new \ReflectionClass($old)->getName());
        $this->assertSame(4, \Utopia\Console::log('log'));
    }

    public function testOldCommandNameResolvesToTheMovedClass(): void
    {
        $old = \Utopia\Command::class;
        if (!class_exists($old)) {
            $this->fail($old . ' does not resolve');
        }

        $this->assertSame(Command::class, new \ReflectionClass($old)->getName());
        $this->assertInstanceOf(Command::class, new \Utopia\Command('echo'));
    }

    /**
     * PHP never autoloads a name while checking a declared type, so a
     * consumer typed against an old name accepts the moved classes only if
     * the alias exists before any code mentions it. A fresh process keeps
     * the earlier tests from registering it first.
     */
    #[RunInSeparateProcess]
    public function testOldNamesInTypeDeclarationsAcceptTheMovedClasses(): void
    {
        $consumer = new OldNamesConsumer(new Command('echo'));

        $this->assertInstanceOf(Command::class, $consumer->command);
        $this->assertSame("'echo'", (string) $consumer->command);
    }
}
