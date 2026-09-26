<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;
use Utopia\DI\Container;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

final class CLITest extends TestCase
{
    public function setUp(): void {}

    public function tearDown(): void {}

    public function testResources(): void
    {
        $cli = new CLI(new Generic(), ['test.php', 'build']);

        $cli->setResource('rand', fn(): int => random_int(0, mt_getrandmax()));
        $cli->setResource('first', fn($second): string => 'first-' . $second, ['second']);
        $cli->setResource('second', fn(): string => 'second');

        $second = $cli->getResource('second');
        $first = $cli->getResource('first');
        $this->assertEquals('second', $second);
        $this->assertEquals('first-second', $first);

        $resource = $cli->getResource('rand');

        $this->assertNotEmpty($resource);
        $this->assertEquals($resource, $cli->getResource('rand'));
        $this->assertEquals($resource, $cli->getResource('rand'));
        $this->assertEquals($resource, $cli->getResource('rand'));
    }

    public function testAppSuccess(): void
    {
        ob_start();

        $cli = new CLI(new Generic(), ['test.php', 'build', '--email=me@example.com']); // Mock command request

        $cli
            ->task('build')
            ->param('email', null, new Text(0), 'Valid email address')
            ->action(function ($email): void {
                echo $email;
            });

        $cli->run();

        $result = ob_get_clean();

        $this->assertSame('me@example.com', $result);
    }

    public function testAppFailure(): void
    {
        ob_start();

        $cli = new CLI(new Generic(), ['test.php', 'build', '--email=me.example.com']); // Mock command request

        $cli
            ->task('build')
            ->param('email', null, new Text(10), 'Valid email address')
            ->action(function ($email): void {
                echo $email;
            });

        $cli->run();

        $result = ob_get_clean();

        $this->assertSame('', $result);
    }

    public function testAppArray(): void
    {
        ob_start();

        $cli = new CLI(new Generic(), ['test.php', 'build', '--email=me@example.com', '--list=item1', '--list=item2']); // Mock command request

        $cli
            ->task('build')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $cli->run();

        $result = ob_get_clean();

        $this->assertSame('me@example.com-item1-item2', $result);
    }

    public function testGetTasks(): void
    {
        $cli = new CLI(new Generic(), ['test.php', 'build', '--email=me@example.com', '--list=item1', '--list=item2']); // Mock command request

        $cli
            ->task('build1')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $cli
            ->task('build2')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $this->assertCount(2, $cli->getTasks());
    }

    public function testGetArgs(): void
    {
        $cli = new CLI(new Generic(), ['test.php', 'build', '--email=me@example.com', '--list=item1', '--list=item2']); // Mock command request

        $cli
            ->task('build1')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $cli
            ->task('build2')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $this->assertCount(2, $cli->getArgs());
        $this->assertSame(['email' => 'me@example.com', 'list' => ['item1', 'item2']], $cli->getArgs());
    }

    public function testHook(): void
    {
        $cli = new CLI(new Generic(), ['test.php', 'build', '--email=me@example.com', '--list=item1', '--list=item2']);

        $cli
            ->init()
            ->action(function (): void {
                echo '(init)-';
            });

        $cli
            ->shutdown()
            ->action(function (): void {
                echo '-(shutdown)';
            });

        $cli
            ->task('build')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        ob_start();

        $cli->run();
        $result = ob_get_clean();

        $this->assertSame('(init)-me@example.com-item1-item2-(shutdown)', $result);
    }

    public function testInjection(): void
    {
        ob_start();

        $cli = new CLI(new Generic(), ['test.php', 'build', '--email=me@example.com']);

        $cli->setResource('test', fn(): string => 'test-value');

        $cli->task('build')
            ->inject('test')
            ->param('email', null, new Text(15), 'valid email address')
            ->action(function (string $test, string $email): void {
                echo $test . '-' . $email;
            });

        $cli->run();

        $result = ob_get_clean();

        $this->assertSame('test-value-me@example.com', $result);
    }

    public function testProvidedContainer(): void
    {
        ob_start();

        $container = new Container();
        $container->set('test', fn(): string => 'test-value');

        $cli = new CLI(new Generic(), ['test.php', 'build'], $container);

        $this->assertNotSame($container, $cli->getContainer());
        $this->assertEquals('test-value', $cli->getResource('test'));

        $cli->task('build')
            ->inject('test')
            ->action(function ($test): void {
                echo $test;
            });

        $cli->run();

        $result = ob_get_clean();

        $this->assertSame('test-value', $result);
    }

    public function testResetPreservesInjectedContainer(): void
    {
        $container = new Container();
        $container->set('base', fn(): string => 'base-value');

        $cli = new CLI(new Generic(), ['test.php', 'build'], $container);
        $cli->setResource('runtime', fn(): string => 'runtime-value');

        $this->assertEquals('base-value', $cli->getResource('base'));
        $this->assertEquals('runtime-value', $cli->getResource('runtime'));

        $cli->reset();

        $this->assertEquals('base-value', $cli->getResource('base'));

        $this->expectException(\Exception::class);
        $cli->getResource('runtime');
    }

    public function testMatch(): void
    {
        $cli = new CLI(new Generic(), ['test.php', 'build2', '--email=me@example.com', '--list=item1', '--list=item2']); // Mock command request

        $cli
            ->task('build1')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $cli
            ->task('build2')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $this->assertSame('build2', $cli->match()->getName());

        $cli = new CLI(new Generic(), ['test.php', 'buildx', '--email=me@example.com', '--list=item1', '--list=item2']); // Mock command request

        $cli
            ->task('build1')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $cli
            ->task('build2')
            ->param('email', null, new Text(0), 'Valid email address')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->action(function (string $email, $list): void {
                echo $email . '-' . implode('-', $list);
            });

        $this->assertEquals(null, $cli->match());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function looseBooleanValuesProvider(): iterable
    {
        yield '"false" string' => ['false', false];
        yield '"true" string' => ['true', true];
        yield '"0" string' => ['0', false];
        yield '"1" string' => ['1', true];
    }

    /**
     * Regression: --flag=false used to arrive as the literal string "false",
     * which PHP's implicit string-to-bool cast turned into `true` at the
     * `bool $flag` parameter boundary. The CLI dispatcher now coerces string
     * inputs whose validator is `Boolean` to a real PHP bool.
     */
    #[DataProvider('looseBooleanValuesProvider')]
    public function testBooleanParamCoercesStringInput(string $input, bool $expected): void
    {
        $captured = null;

        $cli = new CLI(new Generic(), ['test.php', 'build', '--commit=' . $input]);

        $cli
            ->task('build')
            ->param('commit', false, new Boolean(true), 'Commit changes', true)
            ->action(function (bool $commit) use (&$captured): void {
                $captured = $commit;
            });

        $cli->run();

        $this->assertSame($expected, $captured);
    }

    public function testBooleanParamUsesDefaultWhenOmitted(): void
    {
        $captured = null;

        $cli = new CLI(new Generic(), ['test.php', 'build']);

        $cli
            ->task('build')
            ->param('commit', false, new Boolean(true), 'Commit changes', true)
            ->action(function (bool $commit) use (&$captured): void {
                $captured = $commit;
            });

        $cli->run();

        $this->assertFalse($captured);
    }

    public function testBooleanParamCoercionUnwrapsNullable(): void
    {
        $captured = 'untouched';

        $cli = new CLI(new Generic(), ['test.php', 'build', '--commit=false']);

        $cli
            ->task('build')
            ->param('commit', null, new Nullable(new Boolean(true)), 'Commit changes', true)
            ->action(function (bool $commit) use (&$captured): void {
                $captured = $commit;
            });

        $cli->run();

        $this->assertFalse($captured);
    }

    /**
     * Empty-string params bypass `validate()` when optional, so they reach
     * `coerce()` un-validated. We must NOT silently turn them into `false`
     * (callers like Cloud's `Patch.php` use `''` as a "not set" sentinel and
     * later resolve it to `null`/three-state).
     */
    public function testBooleanParamPreservesEmptyStringSentinel(): void
    {
        $captured = 'untouched';

        $cli = new CLI(new Generic(), ['test.php', 'build']);

        $cli
            ->task('build')
            ->param('commit', '', new Boolean(true), 'Commit changes', true)
            ->action(function ($commit) use (&$captured): void {
                $captured = $commit;
            });

        $cli->run();

        $this->assertSame('', $captured);
    }

    public function testNonBooleanValidatorPassesValueThroughUnchanged(): void
    {
        $captured = null;

        $cli = new CLI(new Generic(), ['test.php', 'build', '--name=false']);

        $cli
            ->task('build')
            ->param('name', '', new Text(64), 'A name')
            ->action(function (string $name) use (&$captured): void {
                $captured = $name;
            });

        $cli->run();

        $this->assertSame('false', $captured);
    }

    public function testEscaping(): void
    {
        ob_start();

        $database = 'appwrite://database_db_fra1_self_hosted_0_0?database=appwrite&namespace=_1';

        $cli = new CLI(new Generic(), ['test.php', 'connect', '--database=' . $database]);

        $cli
            ->task('connect')
            ->param('database', null, new Text(2048), 'Database DSN')
            ->action(function ($database): void {
                echo $database;
            });

        $cli->run();

        $result = ob_get_clean();

        $this->assertSame($database, $result);
    }

    public function testParamAliases(): void
    {
        ob_start();

        $cli = new CLI(new Generic(), ['test.php', 'build', '--e=me@example.com']); // Mock command request using alias

        $cli
            ->task('build')
            ->param('email', null, new Text(0), 'Valid email address', false, [], false, false, '', null, ['e', 'em'])
            ->action(function ($email): void {
                echo $email;
            });

        $cli->run();

        $result = ob_get_clean();

        $this->assertSame('me@example.com', $result);
    }
}
