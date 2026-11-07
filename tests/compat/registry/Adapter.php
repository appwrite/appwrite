<?php

namespace Tests\Compat\Registry;

use ArrayObject;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Registry\Registry;

/**
 * Maps tests/compat/registry/spec.json operations onto utopia-php/registry. Glue only: no logic.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        return [
            'get' => ['Read a cached resource', function (Session $s) {
                $registry = new Registry();
                $registry->set('db', fn () => new ArrayObject(['connection']));
                $registry->get('db');

                return fn () => $registry->get('db');
            }],
            'get_fresh' => ['Build a new instance of a resource on read', function (Session $s) {
                $registry = new Registry();
                $registry->set('db', fn () => new ArrayObject(['connection']), true);

                return fn () => $registry->get('db');
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'registry.new' => fn (array $a, Session $s) => $s->handle(new Registry()),
            'registry.set' => function (array $a, Session $s) {
                self::registry($a, $s)->set($a['name'], self::factory($a['factory']), $a['fresh'] ?? false);

                return;
            },
            'registry.get' => fn (array $a, Session $s) => self::plain(self::registry($a, $s)->get($a['name'], $a['fresh'] ?? false)),
            'registry.push' => function (array $a, Session $s) {
                $list = self::registry($a, $s)->get($a['name']);
                $list instanceof ArrayObject || throw new Fault('not a list resource');
                $list[] = $a['value'];

                return;
            },
            'registry.has' => fn (array $a, Session $s) => self::registry($a, $s)->has($a['name']),
            'registry.context' => function (array $a, Session $s) {
                self::registry($a, $s)->context($a['name']);

                return;
            },
            'registry.script' => function (array $a, Session $s) {
                $registry = new Registry();
                $results = [];
                foreach ($a['calls'] as $call) {
                    try {
                        $results[] = match ($call['op']) {
                            'set', 'set_fresh' => (function () use ($registry, $call) {
                                $registry->set($call['name'], self::factory(['kind' => 'counter']), $call['op'] === 'set_fresh');
                            })(),
                            'get' => $registry->get($call['name']),
                            'get_fresh' => $registry->get($call['name'], true),
                            'has' => $registry->has($call['name']),
                            'context' => (function () use ($registry, $call) {
                                $registry->context($call['name']);
                            })(),
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
     * @param array<string, mixed> $factory
     */
    private static function factory(array $factory): callable
    {
        $calls = 0;

        return match ($factory['kind']) {
            'counter' => function () use (&$calls) {
                return ++$calls;
            },
            'value' => fn () => $factory['value'],
            'list' => fn () => new ArrayObject($factory['value']),
            default => throw new Fault("unknown factory {$factory['kind']}"),
        };
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof ArrayObject ? $value->getArrayCopy() : $value;
    }

    private static function registry(array $a, Session $s): Registry
    {
        $registry = $s->get($a['registry']);

        return $registry instanceof Registry ? $registry : throw new Fault('not a Registry');
    }
}
