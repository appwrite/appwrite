<?php

declare(strict_types=1);

namespace Utopia\Console\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\Command;
use Utopia\Console;

final class ConsoleTest extends TestCase
{
    public function testLogs(): void
    {
        $this->assertSame(4, Console::log('log'));
        $this->assertSame(17, Console::success('success'));
        $this->assertSame(14, Console::info('info'));
        $this->assertSame(19, Console::warning('warning'));
        $this->assertSame(15, Console::error('error'));
        $this->assertSame('this is an answer', Console::confirm('this is a question'));
    }

    public function testExecuteBasic(): void
    {
        $command = new Command(PHP_BINARY)
            ->option('-r', "echo 'hello world';");
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 10);

        $this->assertSame('hello world', $output);
        $this->assertSame(0, $code);
    }

    public function testCommandToArray(): void
    {
        $command = new Command('tar')
            ->flag('-cz')
            ->option('-f', 'archive.tar.gz')
            ->option('-C', '/tmp/project')
            ->argument('.');

        $this->assertSame(['tar', '-cz', '-f', 'archive.tar.gz', '-C', '/tmp/project', '.'], $command->toArray());
    }

    public function testCommandToStringEscapesArguments(): void
    {
        $command = new Command('php')
            ->option('-r', "echo 'hello'; rm -rf /");

        $this->assertSame("'php' '-r' 'echo '\''hello'\''; rm -rf /'", $command->toString());
    }

    public function testCommandDefaultValidatorRejectsEmptyArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Command argument cannot be empty');

        new Command('git')->argument('');
    }

    public function testCommandValidatorSuccess(): void
    {
        $command = new Command('git')
            ->argument('checkout')
            ->argument('develop', fn(string $value): bool => \in_array($value, ['main', 'develop', 'staging'], true));

        $this->assertSame(['git', 'checkout', 'develop'], $command->toArray());
    }

    public function testCommandValidatorFailure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid command argument: feature/test; rm -rf /');

        new Command('git')
            ->argument('checkout')
            ->argument('feature/test; rm -rf /', fn(string $value): bool => preg_match('/^[A-Za-z0-9._\/-]+$/', $value) === 1);
    }

    public function testCommandRejectsInvalidFlag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid command flag: verbose');

        new Command('git')->flag('verbose');
    }

    public function testCommandRejectsInvalidOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid command option: -cz');

        new Command('tar')->option('-cz', 'archive.tar.gz');
    }

    public function testCommandRejectsEmptyRedirectTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Command redirect target cannot be empty');

        Command::redirectStdout(new Command('php'), '');
    }

    public function testExecuteArray(): void
    {
        $output = '';
        $stderr = '';
        $input = '';
        $cmd = ['php', '-r', "echo 'hello world';"];
        $code = Console::execute($cmd, $input, $output, $stderr, 10);

        $this->assertSame('hello world', $output);
        $this->assertSame(0, $code);
    }

    public function testExecuteEnvVariables(): void
    {
        $randomData = base64_encode(random_bytes(10));
        putenv("FOO={$randomData}");

        $output = '';
        $stderr = '';
        $input = '';
        $cmd = ['printenv'];
        $code = Console::execute($cmd, $input, $output, $stderr, 10);

        $this->assertSame(0, $code);

        $data = [];
        foreach (explode("\n", $output) as $row) {
            if ($row === '') {
                continue;
            }
            if ($row === '0') {
                continue;
            }
            $kv = explode('=', $row, 2);
            $this->assertCount(2, $kv, $row);
            $data[$kv[0]] = $kv[1];
        }

        $this->assertArrayHasKey('FOO', $data);
        $this->assertSame($randomData, $data['FOO']);
    }

    public function testExecuteStream(): void
    {
        $command = new Command(PHP_BINARY)
            ->option('-r', 'for ($i = 1; $i <= 5; $i++) { echo $i; usleep(1000000); }');
        $output = '';
        $stderr = '';
        $input = '';
        $outputStream = '';
        $code = Console::execute($command, $input, $output, $stderr, 10, function (string $output) use (&$outputStream): void {
            $outputStream .= $output;
        });

        $this->assertEquals('12345', $output);
        $this->assertEquals('12345', $outputStream);
        $this->assertSame(0, $code);
    }

    public function testExecuteStdOut(): void
    {
        $command = new Command(PHP_BINARY)
            ->option('-r', 'fwrite(STDOUT, "success\n");');
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 3);

        $this->assertSame("success\n", $output);
        $this->assertSame('', $stderr);
        $this->assertSame(0, $code);
    }

    public function testExecuteStdErr(): void
    {
        $command = new Command(PHP_BINARY)
            ->option('-r', 'fwrite(STDERR, "error\n");');
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 3);

        $this->assertSame('', $output);
        $this->assertSame("error\n", $stderr);
        $this->assertSame(0, $code);
    }

    public function testExecuteExitCode(): void
    {
        $command = new Command(PHP_BINARY)
            ->option('-r', "echo 'hello world'; exit(2);");
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 10);

        $this->assertSame('hello world', $output);
        $this->assertSame(2, $code);

        $command = new Command(PHP_BINARY)
            ->option('-r', "echo 'hello world'; exit(100);");
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 10);

        $this->assertSame('hello world', $output);
        $this->assertSame(100, $code);
    }

    public function testExecuteTimeout(): void
    {
        $command = new Command(PHP_BINARY)
            ->option('-r', "sleep(1); echo 'hello world'; exit(0);");
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 3);

        $this->assertSame('hello world', $output);
        $this->assertSame(0, $code);

        $command = new Command(PHP_BINARY)
            ->option('-r', "sleep(4); echo 'hello world'; exit(0);");
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 3);

        $this->assertSame('', $output);
        $this->assertSame(1, $code);
    }

    public function testLoop(): void
    {
        $file = __DIR__ . '/../resources/loop.php';
        $command = new Command(PHP_BINARY)
            ->argument($file);
        $input = '';
        $output = '';
        $stderr = '';
        $code = Console::execute($command, $input, $output, $stderr, 30);

        $lines = explode("\n", $output);

        $this->assertGreaterThan(30, \count($lines));
        $this->assertLessThan(50, \count($lines));
        $this->assertSame(1, $code);
    }

    public function testCommandCompositionToString(): void
    {
        $command = Command::and(
            Command::group(
                Command::or(
                    new Command('build'),
                    new Command('build:fallback'),
                ),
            ),
            new Command('publish'),
        );

        $this->assertSame("( 'build' || 'build:fallback' ) && 'publish'", $command->toString());
    }

    public function testCommandPipeToString(): void
    {
        $command = Command::pipe(
            new Command('ps')->flag('-ef'),
            new Command('grep')->argument('php-fpm'),
            new Command('wc')->flag('-l'),
        );

        $this->assertSame("'ps' '-ef' | 'grep' 'php-fpm' | 'wc' '-l'", $command->toString());
    }

    public function testCommandRedirectsToString(): void
    {
        $command = Command::appendStdout(
            Command::pipe(
                new Command('cat')->argument('app.log'),
                new Command('grep')->argument('ERROR'),
            ),
            'errors.log',
        );

        $this->assertSame("'cat' 'app.log' | 'grep' 'ERROR' >> 'errors.log'", $command->toString());
    }

    public function testNestedCommandExpressionToString(): void
    {
        $command = Command::redirectStdout(
            Command::group(
                Command::and(
                    Command::or(
                        new Command('build'),
                        new Command('build:fallback'),
                    ),
                    new Command('publish'),
                ),
            ),
            'deploy.log',
        );

        $this->assertSame("( 'build' || 'build:fallback' && 'publish' ) > 'deploy.log'", $command->toString());
    }

    public function testGroupAnyCommand(): void
    {
        $command = Command::group(new Command('build'));

        $this->assertSame("( 'build' )", $command->toString());
    }

    public function testCompositeCommandIsNotPlain(): void
    {
        $this->assertFalse(Command::and(new Command('build'), new Command('publish'))->isPlain());
    }

    public function testGroupedCommandIsNotPlain(): void
    {
        $this->assertFalse(Command::group(new Command('build'))->isPlain());
    }

    public function testRedirectedCommandIsNotPlain(): void
    {
        $this->assertFalse(Command::redirectStdout(new Command('build'), 'build.log')->isPlain());
    }

    public function testCompositeCommandCannotBeConvertedToArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only plain commands can be converted to an array');

        Command::and(new Command('build'), new Command('publish'))->toArray();
    }

    public function testGroupedCommandCannotBeConvertedToArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only plain commands can be converted to an array');

        Command::group(new Command('build'))->toArray();
    }

    public function testRedirectedCommandCannotBeConvertedToArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only plain commands can be converted to an array');

        Command::redirectStdout(new Command('build'), 'build.log')->toArray();
    }

    public function testCompositeCommandRequiresAtLeastTwoCommands(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Composed commands require at least two commands');

        Command::and(new Command('build'));
    }

    public function testGroupedCommandRejectsAdditionalFlags(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flags, options, and arguments can only be added to plain commands');

        Command::group(new Command('build'))->flag('-v');
    }

    public function testCompositeCommandRejectsAdditionalOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flags, options, and arguments can only be added to plain commands');

        Command::and(new Command('build'), new Command('publish'))->option('--env', 'prod');
    }

    public function testRedirectedCommandRejectsAdditionalArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flags, options, and arguments can only be added to plain commands');

        Command::redirectStdout(new Command('build'), 'build.log')->argument('extra');
    }

    public function testExecutePipeExpression(): void
    {
        $command = Command::pipe(
            new Command(PHP_BINARY)->option('-r', 'echo "alpha\nbeta\n";'),
            new Command('grep')->argument('beta'),
        );
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 10);

        $this->assertSame("beta\n", $output);
        $this->assertSame('', $stderr);
        $this->assertSame(0, $code);
    }

    public function testExecuteGroupedFallbackExpression(): void
    {
        $command = Command::and(
            Command::group(
                Command::or(
                    new Command(PHP_BINARY)->option('-r', 'exit(1);'),
                    new Command(PHP_BINARY)->option('-r', 'echo "fallback";'),
                ),
            ),
            new Command(PHP_BINARY)->option('-r', 'echo " publish";'),
        );
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 10);

        $this->assertSame('fallback publish', $output);
        $this->assertSame('', $stderr);
        $this->assertSame(0, $code);
    }

    public function testExecuteAndStopsOnFailure(): void
    {
        $command = Command::and(
            new Command(PHP_BINARY)->option('-r', 'echo "start"; exit(1);'),
            new Command(PHP_BINARY)->option('-r', 'echo "never";'),
        );
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 10);

        $this->assertSame('start', $output);
        $this->assertSame('', $stderr);
        $this->assertSame(1, $code);
    }

    public function testExecuteOrStopsAfterSuccess(): void
    {
        $command = Command::or(
            new Command(PHP_BINARY)->option('-r', 'echo "done";'),
            new Command(PHP_BINARY)->option('-r', 'echo "fallback";'),
        );
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 10);

        $this->assertSame('done', $output);
        $this->assertSame('', $stderr);
        $this->assertSame(0, $code);
    }

    public function testExecuteGroupedPrecedenceChangesOutcome(): void
    {
        $command = Command::and(
            Command::group(
                Command::or(
                    new Command(PHP_BINARY)->option('-r', 'exit(1);'),
                    new Command(PHP_BINARY)->option('-r', 'echo "fallback";'),
                ),
            ),
            new Command(PHP_BINARY)->option('-r', 'echo " publish";'),
        );
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute($command, $input, $output, $stderr, 10);

        $this->assertSame('fallback publish', $output);
        $this->assertSame(0, $code);
    }

    public function testExecuteRedirectStdoutExpression(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'utopia-console-');
        $this->assertNotFalse($file);

        try {
            $command = Command::redirectStdout(
                new Command(PHP_BINARY)->option('-r', 'echo "saved";'),
                $file,
            );
            $output = '';
            $stderr = '';
            $input = '';
            $code = Console::execute($command, $input, $output, $stderr, 10);

            $this->assertSame('', $output);
            $this->assertSame('', $stderr);
            $this->assertSame(0, $code);
            $this->assertSame('saved', file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testExecuteAppendStdoutExpression(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'utopia-console-');
        $this->assertNotFalse($file);

        try {
            file_put_contents($file, "first\n");

            $command = Command::appendStdout(
                new Command(PHP_BINARY)->option('-r', 'echo "second";'),
                $file,
            );
            $output = '';
            $stderr = '';
            $input = '';
            $code = Console::execute($command, $input, $output, $stderr, 10);

            $this->assertSame('', $output);
            $this->assertSame('', $stderr);
            $this->assertSame(0, $code);
            $this->assertSame("first\nsecond", file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testExecuteRedirectInputExpression(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'utopia-console-');
        $this->assertNotFalse($file);

        try {
            file_put_contents($file, "delta\nalpha\n");

            $command = Command::redirectInput(
                new Command('sort'),
                $file,
            );
            $output = '';
            $stderr = '';
            $input = '';
            $code = Console::execute($command, $input, $output, $stderr, 10);

            $this->assertSame("alpha\ndelta\n", $output);
            $this->assertSame('', $stderr);
            $this->assertSame(0, $code);
        } finally {
            @unlink($file);
        }
    }

    public function testExecuteStringRemainsCompatible(): void
    {
        $output = '';
        $stderr = '';
        $input = '';
        $code = Console::execute('php -r "echo \'hello world\';"', $input, $output, $stderr, 10);

        $this->assertSame('hello world', $output);
        $this->assertSame(0, $code);
    }
}
