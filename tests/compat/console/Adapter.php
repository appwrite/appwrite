<?php

namespace Tests\Compat\Console;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Http\Harness;
use Tests\Compat\Session;
use Utopia\Console\Command;
use Utopia\Console\Console;

/**
 * Maps tests/compat/console/spec.json operations onto utopia-php/console. Glue only: no logic.
 *
 * Output to STDOUT/STDERR, prompts and exit() are observed in a child PHP
 * process (this driver's STDOUT is the protocol), which reports the
 * function's return value on file descriptor 3.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        return [
            'build' => ['Build a plain command with a flag, two options and an argument', fn (Session $s) => fn () => (new Command('tar'))
                ->flag('-cz')
                ->option('-f', 'archive.tar.gz')
                ->option('-C', '/tmp/project')
                ->argument('.')
                ->toArray()],
            'render' => ['Render a redirected pipeline of two commands as a quoted shell string', function (Session $s) {
                $command = Command::appendStdout(
                    Command::pipe((new Command('cat'))->argument('app.log'), (new Command('grep'))->argument("it's an ERROR")),
                    'errors.log',
                );

                return fn () => $command->toString();
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'command.run' => fn (array $a, Session $s) => self::describe(fn () => self::command($a['expr'] ?? null)),
            'console.log' => fn (array $a, Session $s) => self::child(
                'return \\Utopia\\Console\\Console::' . self::method($a['method'] ?? '') . '(base64_decode($argv[1]));',
                [base64_encode((string) ($a['message'] ?? ''))],
                '',
            ),
            'console.confirm' => fn (array $a, Session $s) => self::child(
                'return \\Utopia\\Console\\Console::confirm(base64_decode($argv[1]));',
                [base64_encode((string) ($a['question'] ?? ''))],
                (string) ($a['input'] ?? ''),
            ),
            'console.exit' => fn (array $a, Session $s) => self::child(
                '\\Utopia\\Console\\Console::exit((int) $argv[1]);',
                [(string) (int) ($a['status'] ?? 0)],
                '',
            )['status'],
            'console.title' => fn (array $a, Session $s) => Console::title((string) ($a['title'] ?? '')),
            'console.interactive' => fn (array $a, Session $s) => Console::isInteractive(),
            'console.execute' => fn (array $a, Session $s) => self::execute($a),
            'console.loop' => fn (array $a, Session $s) => self::loop($a),
        ];
    }

    private static function method(string $name): string
    {
        return \in_array($name, ['log', 'success', 'error', 'info', 'warning'], true) ? $name : throw new Fault("unknown method {$name}");
    }

    /**
     * Runs `$code` in a child PHP process with `$args` (base64 where they may hold a NUL byte) as $argv[1..] and
     * `$stdin` as its input; the value it returns is written to fd 3.
     *
     * @param list<string> $args
     * @return array{result: mixed, stdout: string, stderr: string, status: int}
     */
    private static function child(string $code, array $args, string $stdin): array
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . '; $r = (function () use ($argv) { ' . $code . ' })(); fwrite(fopen("php://fd/3", "w"), json_encode($r));';
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, '--', ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new Fault('cannot start php');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $result = stream_get_contents($pipes[3]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        fclose($pipes[3]);
        $status = proc_close($process);

        return ['result' => $result === '' ? null : json_decode($result), 'stdout' => $stdout, 'stderr' => $stderr, 'status' => $status];
    }

    /**
     * How an argument is checked: null (the default), {validator: spec}, or
     * {in: [values]} (a closure accepting those).
     */
    private static function check(mixed $spec): mixed
    {
        if ($spec === null) {
            return null;
        }
        if (isset($spec['validator'])) {
            return Harness::validator($spec['validator']);
        }
        if (isset($spec['in'])) {
            $list = $spec['in'];

            return fn (string $value): bool => \in_array($value, $list, true);
        }

        throw new Fault('unknown check');
    }

    /**
     * Applies flag/option/argument steps to a command.
     *
     * @param list<array<string, mixed>> $steps
     */
    private static function steps(Command $command, array $steps): Command
    {
        foreach ($steps as $step) {
            $op = (string) array_key_first($step);
            $x = $step[$op];
            match ($op) {
                'flag' => $command->flag($x),
                'option' => $command->option($x['key'], $x['value'], self::check($x['check'] ?? null)),
                'argument' => $command->argument($x['value'], self::check($x['check'] ?? null)),
                default => throw new Fault("unknown step {$op}"),
            };
        }

        return $command;
    }

    /**
     * A command from its description: {plain: {executable, steps}},
     * {pipe|and|or: [exprs]}, {group: expr}, {redirect_stdout|append_stdout|redirect_input: {command, path}},
     * {then: {command, steps}} (steps on a built command).
     */
    private static function command(mixed $e): Command
    {
        if (!\is_array($e) || \count($e) !== 1) {
            throw new Fault('command expression: ' . json_encode($e));
        }
        $kind = (string) array_key_first($e);
        $a = $e[$kind];

        return match ($kind) {
            'plain' => self::steps(new Command($a['executable']), $a['steps'] ?? []),
            'pipe' => Command::pipe(...array_map(self::command(...), $a)),
            'and' => Command::and(...array_map(self::command(...), $a)),
            'or' => Command::or(...array_map(self::command(...), $a)),
            'group' => Command::group(self::command($a)),
            'redirect_stdout' => Command::redirectStdout(self::command($a['command']), $a['path']),
            'append_stdout' => Command::appendStdout(self::command($a['command']), $a['path']),
            'redirect_input' => Command::redirectInput(self::command($a['command']), $a['path']),
            'then' => self::steps(self::command($a['command']), $a['steps']),
            default => throw new Fault("unknown command {$kind}"),
        };
    }

    private static function error(\Throwable $e): array
    {
        return ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
    }

    /**
     * A built command: isPlain(), toArray(), toString(), or the error building it raised.
     *
     * @return array<string, mixed>
     */
    private static function describe(callable $build): array
    {
        try {
            $command = $build();
        } catch (Fault $f) {
            throw $f;
        } catch (\Throwable $e) {
            return ['error' => self::error($e)];
        }
        $out = ['plain' => $command->isPlain()];
        foreach (['array' => fn () => $command->toArray(), 'string' => fn () => $command->toString(), 'cast' => fn () => (string) $command] as $key => $read) {
            try {
                $out[$key] = $read();
            } catch (\Throwable $e) {
                $out[$key] = self::error($e);
            }
        }

        return $out;
    }

    /**
     * Console::execute() of {command: expr}, {argv: [...]} or {shell: string}.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function execute(array $a): array
    {
        $program = $a['program'] ?? [];
        $cmd = match (true) {
            isset($program['command']) => self::command($program['command']),
            isset($program['argv']) => $program['argv'],
            isset($program['shell']) => $program['shell'],
            default => throw new Fault('program'),
        };
        $stdout = '';
        $stderr = '';
        $progress = '';
        $onProgress = !empty($a['progress']) ? function (string $chunk) use (&$progress): void {
            $progress .= $chunk;
        } : null;
        try {
            $status = Console::execute($cmd, (string) ($a['stdin'] ?? ''), $stdout, $stderr, (int) ($a['timeout'] ?? -1), $onProgress);
        } catch (\Throwable $e) {
            return ['error' => self::error($e)];
        }

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr, 'progress' => $progress];
    }

    /**
     * Console::loop() with a callback failing on the calls listed in `fail`
     * (Exception "fail <n>"), and, with `on_error`, an error handler that
     * rethrows the `rethrow`-th error it gets.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function loop(array $a): array
    {
        $calls = 0;
        $errors = [];
        $fail = $a['fail'] ?? [];
        $onError = !empty($a['on_error']) ? function (\Exception $e) use (&$errors, $a) {
            $errors[] = $e->getMessage();
            if (\count($errors) >= (int) ($a['rethrow'] ?? 1)) {
                throw $e;
            }
        } : null;
        try {
            Console::loop(function () use (&$calls, $fail) {
                $calls++;
                if ($calls > 1000) {
                    throw new Fault('the loop does not end');
                }
                if (\in_array($calls, $fail, true)) {
                    throw new \Exception("fail {$calls}");
                }
            }, (int) ($a['sleep'] ?? 0), (int) ($a['delay'] ?? 0), $onError);
            $thrown = null;
        } catch (Fault $f) {
            throw $f;
        } catch (\Exception $e) {
            $thrown = $e->getMessage();
        }

        return ['calls' => $calls, 'errors' => $errors, 'thrown' => $thrown];
    }
}
