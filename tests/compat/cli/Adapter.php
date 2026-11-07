<?php

namespace Tests\Compat\Cli;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Http\Harness;
use Tests\Compat\Session;
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;
use Utopia\CLI\Task;
use Utopia\DI\Container;
use Utopia\Servers\Hook;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

/**
 * Maps tests/compat/cli/spec.json operations onto utopia-php/cli. Glue only: no logic.
 */
final class Adapter implements Base, Benchmarks
{
    /** @var list<mixed> What callbacks were called with, in order. */
    private static array $calls = [];

    /** How many times counter resources were built. */
    private static int $built = 0;

    public function benchmarks(): array
    {
        return [
            'parse' => ['Parse a command line with five arguments, one repeated', fn (Session $s) => fn () => new CLI(new Generic(), ['app', 'migrate', '--project=console', '--dry-run=false', '--limit=100', '--only=users', '--only=teams'])],
            'run' => ['Run a task: resolve and validate two params and an injected resource, then call it', function (Session $s) {
                $cli = new CLI(new Generic(), ['app', 'build', '--email=me@example.com', '--commit=false']);
                $cli->setResource('db', fn () => 'primary');
                $cli->task('build')
                    ->param('email', null, new Text(64), 'Email.')
                    ->param('commit', false, new Boolean(true), 'Commit changes.', true)
                    ->inject('db')
                    ->action(fn (string $email, bool $commit, string $db) => null);

                return fn () => $cli->run();
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'cli.run' => fn (array $a, Session $s) => self::cli($a),
            'task.run' => fn (array $a, Session $s) => self::task($a),
            'generic.run' => fn (array $a, Session $s) => self::generic($a),
        ];
    }

    private static function error(\Throwable $e): array
    {
        return ['$error' => ['class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode()]];
    }

    /**
     * A callback argument as JSON: an exception as {"$exception": {class, message, code}}.
     */
    private static function value(mixed $v): mixed
    {
        return $v instanceof \Throwable ? ['$exception' => self::error($v)['$error']] : $v;
    }

    /**
     * Configures a hook (or task) from its description: a callback recording
     * [id, its arguments] that throws `fail` ({message, code}) when given,
     * then params and injections.
     *
     * @param array<string, mixed> $spec
     */
    private static function hook(Hook $hook, array $spec): void
    {
        $id = $spec['id'] ?? '';
        $fail = $spec['fail'] ?? null;
        $hook->action(function (...$args) use ($id, $fail) {
            self::$calls[] = [$id, array_map(self::value(...), $args)];
            if ($fail !== null) {
                throw new \Exception($fail['message'] ?? '', $fail['code'] ?? 0);
            }
        });
        self::declare($hook, $spec);
    }

    /**
     * Declares a hook's description, groups, labels, params and injections.
     *
     * @param array<string, mixed> $spec
     */
    private static function declare(Hook $hook, array $spec): void
    {
        if (isset($spec['desc'])) {
            $hook->desc($spec['desc']);
        }
        if (isset($spec['groups'])) {
            $hook->groups($spec['groups']);
        }
        foreach ($spec['labels'] ?? [] as $key => $value) {
            $hook->label($key, $value);
        }
        foreach ($spec['params'] ?? [] as $p) {
            $spec2 = $p['validator'] ?? ['any' => true];
            $validator = isset($spec2['factory'])
                ? fn () => Harness::validator($spec2['factory'])
                : Harness::validator($spec2);
            $hook->param(
                $p['key'],
                $p['default'] ?? null,
                $validator,
                $p['description'] ?? '',
                (bool) ($p['optional'] ?? false),
                $p['injections'] ?? [],
                (bool) ($p['skip_validation'] ?? false),
                aliases: $p['aliases'] ?? [],
            );
        }
        foreach ($spec['inject'] ?? [] as $name) {
            $hook->inject($name);
        }
    }

    /**
     * A resource factory: `value`, or [value, ...dependencies] with
     * dependencies, or (with `counter`) how many counters were built so far.
     *
     * @param array<string, mixed> $r
     */
    private static function factory(array $r): \Closure
    {
        $value = $r['value'] ?? null;
        if (!empty($r['counter'])) {
            return fn () => ++self::$built;
        }
        if (!empty($r['dependencies'])) {
            return fn (...$deps) => [$value, ...array_map(self::value(...), $deps)];
        }

        return fn () => $value;
    }

    /**
     * A CLI for `argv` (and a parent container holding `container`), driven by steps.
     *
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function cli(array $a): array
    {
        self::$calls = [];
        self::$built = 0;
        $parent = null;
        if (isset($a['container'])) {
            $parent = new Container();
            foreach ((array) $a['container'] as $name => $value) {
                $parent->set($name, fn () => $value);
            }
        }
        try {
            $argv = isset($a['command']) ? ['app', $a['command'], ...($a['arguments'] ?? [])] : ($a['argv'] ?? []);
            $cli = new CLI(new Generic(), $argv, $parent);
        } catch (\Throwable $e) {
            return [self::error($e)];
        }
        $results = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $x = $step[$op];
            try {
                $results[] = match ($op) {
                    'task' => (function () use ($cli, $x) {
                        self::hook($cli->task($x['name']), $x);

                        return true;
                    })(),
                    'bare_task' => $cli->task($x)->getName(),
                    'init', 'shutdown', 'error' => (function () use ($cli, $op, $x) {
                        self::hook($cli->{$op}(), $x);

                        return true;
                    })(),
                    'set_resource' => $cli->setResource($x['name'], self::factory($x), $x['dependencies'] ?? []),
                    'resource' => self::value($cli->getResource($x)),
                    'resources' => array_map(self::value(...), $cli->getResources($x)),
                    'reset' => $cli->reset(),
                    'set_container' => (function () use ($cli, $x) {
                        $container = new Container();
                        foreach ((array) $x as $name => $value) {
                            $container->set($name, fn () => $value);
                        }

                        return $cli->setContainer($container) === $cli;
                    })(),
                    'has' => $cli->getContainer()->has($x),
                    'tasks' => array_map(fn (Task $t) => [$t->getName(), $t->getDesc()], array_values($cli->getTasks())),
                    'task_keys' => array_keys($cli->getTasks()),
                    'args' => $cli->getArgs(),
                    'match' => $cli->match()?->getName(),
                    'parse' => $cli->parse($x),
                    'run' => (function () use ($cli) {
                        self::$calls = [];
                        $error = null;
                        try {
                            $cli->run();
                        } catch (Fault $f) {
                            throw $f;
                        } catch (\Throwable $e) {
                            $error = self::error($e)['$error'];
                        }

                        return ['calls' => self::$calls, 'error' => $error];
                    })(),
                    default => throw new Fault("unknown step {$op}"),
                };
            } catch (Fault $f) {
                throw $f;
            } catch (\Throwable $e) {
                $results[] = self::error($e);
            }
        }

        return $results;
    }

    /**
     * new Task(name) configured and read by steps.
     *
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function task(array $a): array
    {
        $task = new Task($a['name'] ?? '');
        $results = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $x = $step[$op];
            try {
                $results[] = match ($op) {
                    'desc' => $task->desc($x) === $task,
                    'label' => $task->label($x['key'], $x['value']) === $task,
                    'param' => (function () use ($task, $x) {
                        self::declare($task, ['params' => [$x]]);

                        return true;
                    })(),
                    'inject' => $task->inject($x) === $task,
                    'action' => $task->action(fn () => null) === $task,
                    'get' => match ($x) {
                        'name' => $task->getName(),
                        'desc' => $task->getDesc(),
                        'params' => array_keys($task->getParams()),
                        'dependencies' => $task->getDependencies(),
                        'action' => (function () use ($task) {
                            \call_user_func_array($task->getAction(), []);

                            return true;
                        })(),
                        default => throw new Fault("unknown getter {$x}"),
                    },
                    'label_of' => $task->getLabel($x['key'], $x['default'] ?? null),
                    default => throw new Fault("unknown step {$op}"),
                };
            } catch (Fault $f) {
                throw $f;
            } catch (\Throwable $e) {
                $results[] = self::error($e);
            }
        }

        return $results;
    }

    /**
     * The Generic adapter driven by steps: each callback records its name and arguments.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function generic(array $a): array
    {
        $adapter = new Generic();
        $calls = [];
        $results = [];
        foreach ($a['steps'] ?? [] as $step) {
            $results[] = match ($step) {
                'workers' => $adapter->workerNum,
                'start' => $adapter->start(function (...$args) use (&$calls) {
                    $calls[] = ['start', $args];
                }) === $adapter,
                'stop' => $adapter->stop() === $adapter,
                'on_worker_start' => $adapter->onWorkerStart(function (...$args) use (&$calls) {
                    $calls[] = ['worker_start', $args];
                }) === $adapter,
                'on_worker_stop' => $adapter->onWorkerStop(function (...$args) use (&$calls) {
                    $calls[] = ['worker_stop', $args];
                }) === $adapter,
                'on_job' => $adapter->onJob(function (...$args) use (&$calls) {
                    $calls[] = ['job', $args];
                }) === $adapter,
                default => throw new Fault("unknown step {$step}"),
            };
        }

        return ['results' => $results, 'calls' => $calls];
    }
}
