<?php

namespace Tests\Compat\Vcs\Recorder;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Records what packages/vcs/tests ask of an adapter as compat case
 * steps: each test method is a case, each adapter construction a `vcs.new`
 * step, each public method it calls (from the test, not from inside the
 * adapter) a `vcs.<method>` step with the named arguments it passed, and
 * each clone command the test runs a `clone.run` step. The results are not
 * kept: `bin/compat record vcs` fills them in from PHP replaying the steps
 * against a fresh namespace of the fixture server.
 *
 * Addresses the tests reach the fixture server under in the recorder
 * container become `${vcs}/~${ns}/<provider>`, and generated names
 * (uniqid()) are numbered, so a case replays the same in every run.
 */
final class Recorder implements Extension
{
    /** Where the fixture server answers in the recorder container. */
    public const string BASE = 'http://127.0.0.1:8080/~e2e/';

    /** Where the tests' clone directories go in the recorder container. */
    public const string CLONES = '/tmp/vcs-recorder/clones/';

    /** @var array<string, mixed>|null the case being recorded */
    private static ?array $case = null;

    /** @var array<string, array<string, mixed>> the recorded cases by test */
    private static array $cases = [];

    /** @var array<string, string> each test's outcome */
    public static array $outcomes = [];

    private static int $depth = 0;

    private static int $handles = 0;

    /** @var array<string, string> each handle's adapter class */
    private static array $classes = [];

    private static int $names = 0;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class () implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    $test = $event->test();
                    Recorder::begin($test->name());
                }
            },
            new class () implements PassedSubscriber {
                public function notify(Passed $event): void
                {
                    Recorder::outcome('passed');
                }
            },
            new class () implements FailedSubscriber {
                public function notify(Failed $event): void
                {
                    Recorder::outcome('failed: ' . $event->throwable()->message());
                }
            },
            new class () implements ErroredSubscriber {
                public function notify(Errored $event): void
                {
                    Recorder::outcome('errored: ' . $event->throwable()->message());
                }
            },
            new class () implements SkippedSubscriber {
                public function notify(Skipped $event): void
                {
                    Recorder::outcome('skipped: ' . $event->message());
                }
            },
            new class () implements MarkedIncompleteSubscriber {
                public function notify(MarkedIncomplete $event): void
                {
                    Recorder::outcome('incomplete');
                }
            },
            new class () implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    Recorder::end();
                }
            },
        );
    }

    public static function begin(string $name): void
    {
        self::$case = ['name' => $name, 'steps' => []];
        self::$handles = 0;
    }

    public static function outcome(string $outcome): void
    {
        if (self::$case !== null) {
            self::$outcomes[self::$case['name']] = $outcome;
        }
    }

    public static function end(): void
    {
        $case = self::$case;
        self::$case = null;
        if ($case === null || $case['steps'] === [] || str_starts_with(self::$outcomes[$case['name']] ?? '', 'skipped')) {
            return;
        }
        $case['steps'][] = ['op' => 'fixture.requests', 'args' => new \stdClass()];
        self::$cases[$case['name']] = $case;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function cases(): array
    {
        return self::$cases;
    }

    /** uniqid(), numbered: the same names in every recording. */
    public static function uniqid(): string
    {
        return \sprintf('%013x', 0x6a0000000000 + ++self::$names);
    }

    /**
     * A `vcs.new` step; returns the handle the adapter's later steps name.
     *
     * @param array<string, mixed> $args
     */
    public static function construct(array $args): string
    {
        $bind = 'a' . ++self::$handles;
        self::$classes[$bind] = (string) ($args['class'] ?? '');
        unset($args['class']);
        self::step(['op' => 'vcs.new', 'args' => self::out($args), 'bind' => $bind, 'expect' => ['$handle' => '*']]);

        return $bind;
    }

    /**
     * Calls an adapter method, recording it as a step unless the adapter
     * itself is the caller.
     *
     * @param array<string> $names the method's parameter names
     * @param array<mixed> $values the arguments passed
     */
    public static function call(string $handle, string $method, array $names, array $values, \Closure $parent): mixed
    {
        if (self::$depth === 0) {
            $args = ['adapter' => ['$ref' => $handle]];
            foreach ($values as $i => $value) {
                $args[$names[$i] ?? (string) $i] = self::out($value);
            }
            $step = ['op' => 'vcs.' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $method)), 'args' => $args];
            if (self::$classes[$handle] === 'Bitbucket' && \in_array($method, ['createCheckRun', 'getCheckRun', 'updateCheckRun'], true)) {
                $step['mask'] = ['paths' => ['/id', '/url'], 'reason' => 'Bitbucket check runs are keyed check-run-<random_bytes(8) in hex>'];
            }
            self::step($step);
        }
        self::$depth++;
        try {
            return $parent();
        } finally {
            self::$depth--;
        }
    }

    /**
     * exec() of a clone command the test generated: a `clone.run` step.
     *
     * @param array<string> $output
     */
    public static function exec(\Stringable|string $command, string $directory, mixed &$output, mixed &$exitCode): void
    {
        $command = (string) $command;
        if (str_contains($command, '@github.com/')) {
            // GitHub's clone URL names github.com itself, which no case may reach
            $output = ['fatal: github.com is not reachable from the recorder'];
            $exitCode = 128;

            return;
        }
        self::step(['op' => 'clone.run', 'args' => ['command' => self::out($command), 'directory' => self::out($directory)]]);
        exec($command . ' 2>&1', $output, $exitCode);
    }

    /**
     * @param array<string, mixed> $step
     */
    private static function step(array $step): void
    {
        if (self::$case !== null) {
            self::$case['steps'][] = $step;
        }
    }

    /** A value as a case carries it. */
    private static function out(mixed $value): mixed
    {
        if (\is_string($value)) {
            $host = substr(self::BASE, \strlen('http://'));
            $value = str_replace(
                [self::BASE . 'catcher', self::BASE, str_replace('/', '\\/', self::BASE), $host, self::CLONES],
                ['${vcs}/~${ns}/catcher', '${vcs}/~${ns}/', str_replace('/', '\\/', '${vcs}/~${ns}/'), '${vcs-host}/~${ns}/', '/tmp/compat-fs/${ns}/'],
                $value,
            );

            return mb_check_encoding($value, 'UTF-8') ? $value : ['$bytes' => base64_encode($value)];
        }
        if (\is_array($value)) {
            return array_map(self::out(...), $value);
        }

        return $value;
    }
}
