<?php

namespace Tests\Compat\Orchestration;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Codec;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Orchestration\Adapter as OrchestrationAdapter;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Container\Stats;
use Utopia\Orchestration\Network;
use Utopia\Orchestration\Orchestration;

require_once __DIR__ . '/Operations.php';

/**
 * Maps tests/compat/orchestration/spec.json operations onto
 * utopia-php/orchestration. Glue only: no logic.
 *
 * Operations that need no daemon run here. Those that reach Docker run in
 * the `orchestration-php` server (Operations.php, started by services.sh),
 * whose container has the Docker socket the library expects; this driver's
 * container has none. Containers and networks are created on the local
 * daemon only, named after the case's namespace, and removed by the cases
 * (and by `services.sh down`).
 */
final class Adapter implements Base, Benchmarks
{
    private const string COMMAND = "sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'";

    public function benchmarks(): array
    {
        return [
            'parse_command' => ['Split a runtime start command with a quoted script', function (Session $s) {
                $orchestration = new Orchestration(new DockerCLI());

                return fn () => $orchestration->parseCommandString(self::COMMAND);
            }],
            'filter_env_key' => ['Filter ten environment variable names', function (Session $s) {
                $adapter = new DockerCLI();
                $keys = ['APPWRITE_FUNCTION_ID', 'APPWRITE FUNCTION NAME', '_APP_REDIS.HOST', 'path-1', 'ÉTÉ', 'A$B', 'x', '', 'OPEN_RUNTIMES_SECRET', 'a.b.c-d_e'];

                return function () use ($adapter, $keys) {
                    foreach ($keys as $key) {
                        $adapter->filterEnvKey($key);
                    }
                };
            }],
        ];
    }

    public function operations(): array
    {
        $remote = static fn (string $op) => static fn (array $a, Session $s) => self::remote($op, $a, $s);

        return [
            'parse_command' => fn (array $a, Session $s) => new Orchestration(new DockerCLI())->parseCommandString($a['command']),
            'filter_env_key' => fn (array $a, Session $s) => new DockerCLI()->filterEnvKey($a['key']),
            'container.new' => function (array $a, Session $s) {
                $container = new Container(...self::fields($a, ['name', 'id', 'status', 'labels']));
                foreach ($a['set'] ?? [] as $field => $value) {
                    match ($field) {
                        'name' => $container->setName($value),
                        'id' => $container->setId($value),
                        'status' => $container->setStatus($value),
                        'labels' => $container->setLabels(self::labels($value)),
                        default => throw new Fault('unknown container field ' . $field),
                    };
                }

                return Operations::container($container);
            },
            'network.new' => function (array $a, Session $s) {
                $network = new Network(...self::fields($a, ['name', 'id', 'driver', 'scope']));
                foreach ($a['set'] ?? [] as $field => $value) {
                    match ($field) {
                        'name' => $network->setName($value),
                        'id' => $network->setId($value),
                        'driver' => $network->setDriver($value),
                        'scope' => $network->setScope($value),
                        default => throw new Fault('unknown network field ' . $field),
                    };
                }

                return Operations::network($network);
            },
            'stats.new' => fn (array $a, Session $s) => Operations::stats(new Stats(
                $a['container_id'],
                $a['container_name'],
                $a['cpu_usage'],
                $a['memory_usage'],
                $a['disk_io'],
                $a['memory_io'],
                $a['network_io'],
            )),
            'constants' => fn (array $a, Session $s) => [
                'RESTART_NO' => OrchestrationAdapter::RESTART_NO,
                'RESTART_ALWAYS' => OrchestrationAdapter::RESTART_ALWAYS,
                'RESTART_ON_FAILURE' => OrchestrationAdapter::RESTART_ON_FAILURE,
                'RESTART_UNLESS_STOPPED' => OrchestrationAdapter::RESTART_UNLESS_STOPPED,
            ],
            'orchestration.new' => function (array $a, Session $s) {
                // Built on the server too, so a constructor that fails (a login) is reported.
                $built = self::remote('orchestration.new', ['orchestration' => $a], $s);

                return $built === true ? $s->handle(['orchestration' => $a]) : $built;
            },
            'orchestration.call' => function (array $a, Session $s) {
                $handle = $s->get($a['orchestration']);
                if (!\is_array($handle) || !isset($handle['orchestration'])) {
                    throw new Fault('not an orchestration');
                }

                return self::remote('orchestration.call', ['orchestration' => $handle['orchestration']] + $a, $s);
            },
            'daemon.inspect' => $remote('daemon.inspect'),
            'daemon.restarted' => $remote('daemon.restarted'),
            'daemon.wait' => $remote('daemon.wait'),
            'daemon.wait_gone' => $remote('daemon.wait_gone'),
            'daemon.cleanup' => $remote('daemon.cleanup'),
            'fixture.write' => $remote('fixture.write'),
        ];
    }

    /**
     * The constructor arguments given, in order, up to the first one left out.
     *
     * @param list<string> $names
     * @return list<mixed>
     */
    private static function fields(array $a, array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            if (!\array_key_exists($name, $a)) {
                break;
            }
            $out[] = $name === 'labels' ? self::labels($a[$name]) : $a[$name];
        }

        return $out;
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @return array<string, string>
     */
    private static function labels(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            $out[$pair[0]] = $pair[1];
        }

        return $out;
    }

    /** Runs an operation on the `orchestration-php` server. */
    private static function remote(string $op, array $a, Session $s): mixed
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode(['op' => $op, 'args' => Codec::encode($a)], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'ignore_errors' => true,
            'timeout' => 600,
        ]]);
        $body = @file_get_contents($s->service('orchestration-php'), false, $context);
        if ($body === false) {
            throw new Fault('the orchestration-php server is not reachable (tests/compat/orchestration/services.sh up)');
        }
        $reply = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (isset($reply->fault)) {
            throw new Fault((string) $reply->fault);
        }
        if (isset($reply->err)) {
            throw new Fault('orchestration-php: ' . $reply->err->class . ': ' . json_encode($reply->err->message));
        }

        return Codec::decode($reply->ok);
    }
}
