<?php

namespace Tests\Compat\Di;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\DI\Container;
use Utopia\DI\Exceptions\ContainerException;

/**
 * Maps tests/compat/di/spec.json operations onto utopia-php/di. Glue only: no logic.
 */
final class Adapter implements Base, Benchmarks
{
    /** Ids of the containers a script works on, in dependency order. */
    private const IDS = ['a', 'b', 'c', 'd', 'e'];

    public function benchmarks(): array
    {
        return [
            'get' => ['Resolve a built dependency', function (Session $s) {
                $container = new Container();
                $container->set('db', fn () => new \stdClass());
                $container->get('db');

                return fn () => $container->get('db');
            }],
            'parent' => ['Resolve a dependency built by the parent container', function (Session $s) {
                $parent = new Container();
                $parent->set('db', fn () => new \stdClass());
                $child = new Container($parent);
                $child->get('db');

                return fn () => $child->get('db');
            }],
            'build' => ['Build a dependency from two resolved dependencies', function (Session $s) {
                $container = new Container();
                $container->set('host', fn () => 'db.internal');
                $container->set('port', fn () => 5432);
                $container->get('host');
                $container->get('port');
                $factory = fn (string $host, int $port) => [$host, $port];

                return fn () => $container->set('dsn', $factory, ['host', 'port'])->get('dsn');
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'di.new' => fn (array $a, Session $s) => $s->handle(new Container(isset($a['parent']) ? self::container($a['parent'], $s) : null)),
            'di.set' => function (array $a, Session $s) {
                $container = self::container($a['container'], $s);
                $result = $container->set($a['id'], self::factory($a['factory'], $a['id']), $a['dependencies'] ?? []);

                return $result === $container;
            },
            'di.get' => fn (array $a, Session $s) => self::container($a['container'], $s)->get($a['id']),
            'di.has' => fn (array $a, Session $s) => self::container($a['container'], $s)->has($a['id']),
            'di.script' => function (array $a, Session $s) {
                // Containers 0 (root), 1 (child of 0), 2 (child of 1), 3 (child of 0).
                $root = new Container();
                $child = new Container($root);
                $containers = [$root, $child, new Container($child), new Container($root)];
                $results = [];
                foreach ($a['calls'] as $call) {
                    $container = $containers[$call['container']] ?? throw new Fault('no such container');
                    try {
                        $results[] = match ($call['op']) {
                            'set' => $container->set($call['id'], self::factory(['kind' => 'trace'], $call['id']), self::dependencies($call)) === $container,
                            'get' => $container->get($call['id']),
                            'has' => $container->has($call['id']),
                            default => throw new Fault("unknown call {$call['op']}"),
                        };
                    } catch (Fault $fault) {
                        throw $fault;
                    } catch (\Throwable $error) {
                        $results[] = ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
                    }
                }

                return $results;
            },
        ];
    }

    /**
     * The ids a scripted factory depends on: offsets after its own id in IDS
     * (so dependencies never form a cycle), `missing` past the end.
     *
     * @return list<string>
     */
    private static function dependencies(array $call): array
    {
        $index = array_search($call['id'], self::IDS, true);
        $index === false && throw new Fault('scripted ids are ' . implode(', ', self::IDS));

        return array_map(fn (int $offset) => self::IDS[$index + $offset] ?? 'missing', $call['dependencies'] ?? []);
    }

    /**
     * @param array<string, mixed> $factory
     */
    private static function factory(array $factory, string $id): callable
    {
        $calls = 0;

        return match ($factory['kind']) {
            'value' => fn () => $factory['value'],
            'counter' => function () use (&$calls) {
                return ++$calls;
            },
            'format' => fn (...$dependencies) => strtr($factory['template'], array_combine(
                array_map(fn (int $i) => '{' . $i . '}', array_keys($dependencies)),
                array_map(strval(...), $dependencies),
            ) ?: []),
            'list' => fn (...$dependencies) => $dependencies,
            'sum' => fn (int ...$numbers) => array_sum($numbers),
            'fail' => fn () => throw new ContainerException($factory['message']),
            'trace' => function (...$dependencies) use (&$calls, $id) {
                return $id . '#' . ++$calls . '(' . implode(',', $dependencies) . ')';
            },
            default => throw new Fault("unknown factory {$factory['kind']}"),
        };
    }

    private static function container(mixed $handle, Session $s): Container
    {
        $container = $s->get($handle);

        return $container instanceof Container ? $container : throw new Fault('not a Container');
    }
}
