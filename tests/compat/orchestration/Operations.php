<?php

namespace Tests\Compat\Orchestration;

use Tests\Compat\Fault;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Container\Stats;
use Utopia\Orchestration\Network;
use Utopia\Orchestration\Orchestration;

/**
 * The orchestration operations that reach Docker, run by the
 * `orchestration-php` server (fixtures/server.php) in a container that has
 * the Docker socket at /var/run/docker.sock, where the library expects it
 * (the PHP driver's container has none). Glue only: no logic. Its twin is
 * crates/tools/compat/src/libs/orchestration.rs.
 */
final class Operations
{
    /** Labels Docker Desktop adds to every container it lists. */
    private const DAEMON_LABELS = 'desktop.docker.io/';

    /**
     * @param array<string, mixed> $a
     */
    public static function run(string $op, array $a): mixed
    {
        return match ($op) {
            'orchestration.new' => (static function () use ($a): mixed {
                try {
                    self::orchestration($a['orchestration'] ?? []);

                    return true;
                } catch (Fault $fault) {
                    throw $fault;
                } catch (\Throwable $error) {
                    return ['error' => ['class' => $error::class, 'message' => $error->getMessage()]];
                }
            })(),
            'orchestration.call' => self::call(self::orchestration($a['orchestration'] ?? []), $a),
            'daemon.inspect' => self::inspect($a['container']),
            'daemon.restarted' => (int) trim((string) shell_exec('docker inspect --format {{.RestartCount}} ' . escapeshellarg($a['container']) . ' 2>/dev/null')) > 0,
            'daemon.wait' => (static function () use ($a): bool {
                usleep((int) ($a['seconds'] * 1_000_000));

                return true;
            })(),
            'daemon.wait_gone' => self::waitGone($a['name'], (int) ($a['seconds'] ?? 30)),
            'daemon.cleanup' => self::cleanup($a['match']),
            'fixture.write' => self::write($a['path'], $a['content']),
            default => throw new Fault("orchestration-php: unknown operation `{$op}`"),
        };
    }

    /**
     * An orchestrator for a handle's settings: the adapter (`cli` or
     * `api`), its constructor's credentials, and the setters.
     *
     * @param array<string, mixed> $config
     */
    public static function orchestration(array $config): Orchestration
    {
        $credentials = $config['credentials'] ?? [];
        $adapter = match ($config['adapter'] ?? 'cli') {
            'cli' => new DockerCLI($credentials['username'] ?? null, $credentials['password'] ?? null),
            'api' => new DockerAPI($credentials['username'] ?? null, $credentials['password'] ?? null, $credentials['email'] ?? null),
            default => throw new Fault('unknown adapter ' . json_encode($config['adapter'])),
        };
        $orchestration = new Orchestration($adapter);
        if (isset($config['namespace'])) {
            $orchestration->setNamespace($config['namespace']);
        }
        if (isset($config['cpus'])) {
            $orchestration->setCpus($config['cpus']);
        }
        if (isset($config['memory'])) {
            $orchestration->setMemory($config['memory']);
        }
        if (isset($config['swap'])) {
            $orchestration->setSwap($config['swap']);
        }

        return $orchestration;
    }

    /**
     * One orchestrator method: {result | error}, and `output` for execute().
     * Listings keep the entries whose name contains `match` (the case's
     * namespace), since the daemon is shared with everything else the host
     * runs.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function call(Orchestration $o, array $a): array
    {
        $match = $a['match'] ?? null;
        $keep = static fn (array $list, callable $name): array => array_values(array_filter(
            $list,
            static fn ($item): bool => $match === null || str_contains($name($item), $match),
        ));
        $output = '';
        $filters = self::pairs($a['filters'] ?? []);
        try {
            $result = match ($a['method']) {
                'createNetwork' => $o->createNetwork($a['name'], $a['internal'] ?? false),
                'removeNetwork' => $o->removeNetwork($a['name']),
                'networkConnect' => $o->networkConnect($a['container'], $a['network']),
                'networkDisconnect' => $o->networkDisconnect($a['container'], $a['network'], $a['force'] ?? false),
                'networkExists' => $o->networkExists($a['name']),
                // Sorted by name: the API lists networks in the daemon's map order.
                'listNetworks' => (static function (array $networks): array {
                    usort($networks, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

                    return $networks;
                })(array_map(self::network(...), $keep($o->listNetworks(), static fn (Network $n) => $n->getName()))),
                'getStats' => array_map(self::stats(...), $keep($o->getStats($a['container'] ?? null, $filters), static fn (Stats $s) => $s->getContainerName())),
                'pull' => $o->pull($a['image']),
                'list' => array_map(self::listed(...), $keep($o->list($filters), static fn (Container $c) => $c->getName())),
                'run' => $o->run(
                    $a['image'],
                    $a['name'],
                    $a['command'] ?? [],
                    $a['entrypoint'] ?? '',
                    $a['workdir'] ?? '',
                    $a['volumes'] ?? [],
                    self::pairs($a['vars'] ?? []),
                    $a['mount_folder'] ?? '',
                    self::pairs($a['labels'] ?? []),
                    $a['hostname'] ?? '',
                    $a['remove'] ?? false,
                    $a['network'] ?? '',
                    $a['restart'] ?? Adapter::RESTART_NO,
                ),
                'execute' => $o->execute($a['name'], $a['command'], $output, self::pairs($a['vars'] ?? []), $a['timeout'] ?? -1),
                'remove' => $o->remove($a['name'], $a['force'] ?? false),
                default => throw new Fault('unknown orchestration method ' . $a['method']),
            };
            $out = ['result' => $result];
        } catch (Fault $fault) {
            throw $fault;
        } catch (\Throwable $error) {
            $out = ['error' => ['class' => $error::class, 'message' => $error->getMessage()]];
        }
        if ($a['method'] === 'execute') {
            $out['output'] = ($a['summary'] ?? false)
                ? ['length' => \strlen($output), 'start' => substr($output, 0, 8), 'end' => substr($output, -8)]
                : $output;
        }

        return $out;
    }

    /**
     * `[[key, value], ...]` as the associative array PHP callers pass.
     *
     * @param list<array{0: string, 1: string}> $pairs
     * @return array<string, string>
     */
    private static function pairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            $out[$pair[0]] = $pair[1];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function container(Container $c): array
    {
        return ['name' => $c->getName(), 'id' => $c->getId(), 'status' => $c->getStatus(), 'labels' => (object) $c->getLabels()];
    }

    /**
     * A container list() returned, without the labels the daemon added (Docker
     * Desktop's port scheme and the host side of each bind, which a Linux Docker
     * Engine never adds): the library's own labels and the caller's are
     * compared on every daemon.
     *
     * @return array<string, mixed>
     */
    private static function listed(Container $c): array
    {
        $labels = array_filter(
            $c->getLabels(),
            static fn (int|string $key): bool => !str_starts_with((string) $key, self::DAEMON_LABELS),
            ARRAY_FILTER_USE_KEY,
        );
        $out = self::container($c);
        $out['labels'] = (object) $labels;

        return $out;
    }

    /** @return array<string, mixed> */
    public static function network(Network $n): array
    {
        return ['name' => $n->getName(), 'id' => $n->getId(), 'driver' => $n->getDriver(), 'scope' => $n->getScope()];
    }

    /** @return array<string, mixed> */
    public static function stats(Stats $s): array
    {
        $io = static fn (array $io): array => ['in' => (float) ($io['in'] ?? 0), 'out' => (float) ($io['out'] ?? 0)];

        return [
            'container_id' => $s->getContainerId(),
            'container_name' => $s->getContainerName(),
            'cpu_usage' => $s->getCpuUsage(),
            'memory_usage' => $s->getMemoryUsage(),
            'disk_io' => $io($s->getDiskIO()),
            'memory_io' => $io($s->getMemoryIO()),
            'network_io' => $io($s->getNetworkIO()),
        ];
    }

    /**
     * What Docker made of a container: the settings run() passes.
     *
     * @return array<string, mixed>|null
     */
    private static function inspect(string $container): ?array
    {
        $json = shell_exec('docker inspect ' . escapeshellarg($container) . ' 2>/dev/null');
        $inspected = json_decode((string) $json, true)[0] ?? null;
        if (!\is_array($inspected)) {
            return null;
        }
        $config = $inspected['Config'];
        $host = $inspected['HostConfig'];

        return [
            'name' => ltrim($inspected['Name'], '/'),
            'image' => $config['Image'],
            'cmd' => $config['Cmd'],
            'entrypoint' => $config['Entrypoint'],
            'workdir' => $config['WorkingDir'],
            'hostname' => $config['Hostname'],
            'env' => (static function (array $env): array {
                // The client passes --env flags through a map, so their order is not kept.
                sort($env);

                return $env;
            })($config['Env'] ?? []),
            'labels' => $config['Labels'],
            'binds' => (static function (?array $binds): ?array {
                // The client passes --volume flags through a map, so their order is not kept.
                if ($binds !== null) {
                    sort($binds);
                }

                return $binds;
            })($host['Binds'] ?? null),
            'memory' => $host['Memory'],
            'swap' => $host['MemorySwap'],
            'nano_cpus' => $host['NanoCpus'],
            'cpu_quota' => $host['CpuQuota'],
            'cpu_period' => $host['CpuPeriod'],
            'auto_remove' => $host['AutoRemove'],
            'network_mode' => $host['NetworkMode'],
            'restart' => $host['RestartPolicy']['Name'],
            'networks' => array_keys($inspected['NetworkSettings']['Networks'] ?? []),
        ];
    }

    private static function waitGone(string $name, int $seconds): bool
    {
        $deadline = time() + $seconds;
        do {
            $found = trim((string) shell_exec('docker ps -aq --filter ' . escapeshellarg('name=^' . $name . '$') . ' 2>/dev/null'));
            if ($found === '') {
                return true;
            }
            usleep(250_000);
        } while (time() < $deadline);

        return false;
    }

    private static function cleanup(string $match): bool
    {
        $ids = trim((string) shell_exec('docker ps -aq --filter ' . escapeshellarg('name=' . $match) . ' 2>/dev/null'));
        if ($ids !== '') {
            shell_exec('docker rm -f ' . implode(' ', array_map(escapeshellarg(...), explode("\n", $ids))) . ' >/dev/null 2>&1');
        }
        $networks = trim((string) shell_exec('docker network ls -q --filter ' . escapeshellarg('name=' . $match) . ' 2>/dev/null'));
        if ($networks !== '') {
            shell_exec('docker network rm ' . implode(' ', array_map(escapeshellarg(...), explode("\n", $networks))) . ' >/dev/null 2>&1');
        }

        return true;
    }

    private static function write(string $path, string $content): string
    {
        if (!str_starts_with($path, '/tmp/compat-fs/') || str_contains($path, '..')) {
            throw new Fault('fixture.write writes under /tmp/compat-fs only');
        }
        @mkdir(\dirname($path), 0777, true);
        file_put_contents($path, $content);

        return $path;
    }
}
