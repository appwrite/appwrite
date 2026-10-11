<?php

namespace Tests\Compat\Balancer;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Algorithm\Random;
use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;
use Utopia\Telemetry\Adapter\Test;

/**
 * Maps tests/compat/balancer/spec.json operations onto utopia-php/balancer. Glue only.
 *
 * Filters are data ({key, op, value}): the closure compares the option's state
 * with PHP's own operators. A Random algorithm with a `seed` is created after
 * mt_srand($seed), so its picks are reproducible.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        $workers = static function (): array {
            $options = [];
            for ($i = 1; $i <= 8; $i++) {
                $options[] = new Option(['hostname' => "worker-{$i}", 'online' => $i % 3 !== 0, 'cpu' => ($i * 17) % 100]);
            }

            return $options;
        };
        $balancer = static function (Algorithm $algorithm, bool $filtered) use ($workers): Balancer {
            $balancer = new Balancer($algorithm);
            foreach ($workers() as $option) {
                $balancer->addOption($option);
            }
            if ($filtered) {
                $balancer->addFilter(fn (Option $o): bool => $o->getState('online') === true);
                $balancer->addFilter(fn (Option $o): bool => $o->getState('cpu') < 50);
            }

            return $balancer;
        };

        return [
            'round_robin' => ['Choose the next of 8 options with RoundRobin', function (Session $s) use ($balancer) {
                $b = $balancer(new RoundRobin(-1), false);

                return fn () => $b->run();
            }],
            'filtered' => ['Choose the first of 8 options passing two filters (online, cpu < 50)', function (Session $s) use ($balancer) {
                $b = $balancer(new First(), true);

                return fn () => $b->run();
            }],
            'random' => ['Choose a random one of 8 options', function (Session $s) use ($balancer) {
                mt_srand(1);
                $b = $balancer(new Random(), false);

                return fn () => $b->run();
            }],
            'group' => ['Run a group of a filtered balancer that chooses nothing and a RoundRobin fallback', function (Session $s) use ($balancer) {
                $none = $balancer(new First(), true);
                $none->addFilter(fn (Option $o): bool => false);
                $group = new Group('bench');
                $group->add($none)->add($balancer(new RoundRobin(-1), false));

                return fn () => $group->run();
            }],
            'option_state' => ['Read a state value of an option with three values', function (Session $s) {
                $option = new Option(['hostname' => 'worker-1', 'online' => true, 'cpu' => 35]);

                return fn () => $option->getState('cpu', 0);
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'option.new' => fn (array $a, Session $s) => $s->handle(new Option(self::state($a['state'] ?? []))),
            'option.get_state' => fn (array $a, Session $s) => self::option($a['option'], $s)->getState($a['key'], $a['default'] ?? null),
            'option.set_state' => function (array $a, Session $s) {
                self::option($a['option'], $s)->setState($a['key'], $a['value'] ?? null);

                return;
            },
            'option.delete_state' => function (array $a, Session $s) {
                self::option($a['option'], $s)->deleteState($a['key']);

                return;
            },
            'option.get_states' => fn (array $a, Session $s) => self::option($a['option'], $s)->getStates(),
            'option.scenario' => function (array $a, Session $s) {
                $option = new Option(self::state($a['state'] ?? []));
                foreach ($a['set'] ?? [] as $pair) {
                    $option->setState($pair['k'], $pair['v'] ?? null);
                }
                foreach ($a['delete'] ?? [] as $key) {
                    $option->deleteState($key);
                }
                $gets = [];
                foreach ($a['get'] ?? [] as $key) {
                    $gets[] = $option->getState($key, $a['default'] ?? null);
                }

                return ['states' => $option->getStates(), 'gets' => $gets];
            },

            'algorithm.new' => fn (array $a, Session $s) => $s->handle(self::algorithm($a)),
            'algorithm.name' => fn (array $a, Session $s) => self::algo($a['algorithm'], $s)->getName(),
            'algorithm.run' => function (array $a, Session $s) {
                $options = array_map(fn ($h) => self::option($h, $s), $a['options'] ?? []);
                $chosen = self::algo($a['algorithm'], $s)->run($options);

                return $chosen === null ? null : array_search($chosen, $options, true);
            },
            'round_robin.get_index' => fn (array $a, Session $s) => self::roundRobin($a['algorithm'], $s)->getIndex(),
            'round_robin.set_index' => function (array $a, Session $s) {
                self::roundRobin($a['algorithm'], $s)->setIndex($a['index']);

                return;
            },

            'balancer.new' => fn (array $a, Session $s) => $s->handle(new Balancer(self::algo($a['algorithm'], $s))),
            'balancer.get_algo' => fn (array $a, Session $s) => self::balancer($a['balancer'], $s)->getAlgo()->getName(),
            'balancer.add_option' => function (array $a, Session $s) {
                self::balancer($a['balancer'], $s)->addOption(self::option($a['option'], $s));

                return;
            },
            'balancer.add_filter' => function (array $a, Session $s) {
                self::balancer($a['balancer'], $s)->addFilter(self::filter($a['filter']));

                return;
            },
            'balancer.get_options' => fn (array $a, Session $s) => self::states(self::balancer($a['balancer'], $s)->getOptions()),
            'balancer.get_filtered_options' => fn (array $a, Session $s) => self::states(self::balancer($a['balancer'], $s)->getFilteredOptions()),
            'balancer.run' => fn (array $a, Session $s) => self::balancer($a['balancer'], $s)->run()?->getStates(),
            'balancer.scenario' => fn (array $a, Session $s) => self::scenario($a),

            'group.new' => fn (array $a, Session $s) => $s->handle(new Group($a['name'] ?? null)),
            'group.add' => function (array $a, Session $s) {
                self::group($a['group'], $s)->add(self::balancer($a['balancer'], $s));

                return;
            },
            'group.get_options' => fn (array $a, Session $s) => self::states(self::group($a['group'], $s)->getOptions()),
            'group.run' => fn (array $a, Session $s) => self::group($a['group'], $s)->run()?->getStates(),
            'group.set_telemetry' => function (array $a, Session $s) {
                self::group($a['group'], $s)->setTelemetry($s->get($a['telemetry']));

                return;
            },
            'telemetry.new' => fn (array $a, Session $s) => $s->handle(new Test()),
            'telemetry.dump' => function (array $a, Session $s) {
                // The Test adapter's instruments expose the values they recorded.
                $t = $s->get($a['telemetry']);

                return array_map(null, array_keys($t->histograms), array_map(static fn ($h) => \count($h->values), array_values($t->histograms))) ?: [];
            },
        ];
    }

    /**
     * A balancer over `options` (states) with `filters`, run `runs` times:
     * the position of each choice in `options`, or null.
     *
     * @param array<string, mixed> $a
     * @return list<int|null>
     */
    private static function scenario(array $a): array
    {
        $balancer = new Balancer(self::algorithm($a['algorithm']));
        $options = array_map(fn ($state) => new Option(self::state($state)), $a['options'] ?? []);
        foreach ($options as $option) {
            $balancer->addOption($option);
        }
        foreach ($a['filters'] ?? [] as $filter) {
            $balancer->addFilter(self::filter($filter));
        }
        $picks = [];
        for ($i = 0; $i < $a['runs']; $i++) {
            $chosen = $balancer->run();
            $picks[] = $chosen === null ? null : array_search($chosen, $options, true);
        }

        return $picks;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function algorithm(array $a): Algorithm
    {
        return match ($a['type']) {
            'First' => new First(),
            'Last' => new Last(),
            'Random' => (static function () use ($a): Random {
                if (isset($a['seed'])) {
                    mt_srand($a['seed']);
                }

                return new Random();
            })(),
            'RoundRobin' => new RoundRobin($a['index']),
            default => throw new Fault("unknown algorithm {$a['type']}"),
        };
    }

    /**
     * A filter from {key, op, value}: the option's state against the value
     * with PHP's operator.
     *
     * @param array<string, mixed> $f
     */
    private static function filter(array $f): callable
    {
        $key = $f['key'] ?? '';
        $value = $f['value'] ?? null;

        return match ($f['op']) {
            '===' => fn (Option $o): bool => $o->getState($key) === $value,
            '!==' => fn (Option $o): bool => $o->getState($key) !== $value,
            '==' => fn (Option $o): bool => $o->getState($key) == $value,
            '<' => fn (Option $o): bool => $o->getState($key) < $value,
            '>' => fn (Option $o): bool => $o->getState($key) > $value,
            'truthy' => fn (Option $o): bool => (bool) $o->getState($key),
            'false' => fn (Option $o): bool => false,
            default => throw new Fault("unknown filter op {$f['op']}"),
        };
    }

    /**
     * @param list<Option> $options
     * @return list<array<string, mixed>>
     */
    private static function states(array $options): array
    {
        return array_map(fn (Option $o) => $o->getStates(), $options);
    }

    /**
     * An empty JSON object decodes to stdClass; a state is an array.
     *
     * @return array<string, mixed>
     */
    private static function state(mixed $state): array
    {
        return \is_array($state) ? $state : [];
    }

    private static function option(mixed $handle, Session $s): Option
    {
        $o = $s->get($handle);

        return $o instanceof Option ? $o : throw new Fault('not an option');
    }

    private static function algo(mixed $handle, Session $s): Algorithm
    {
        $o = $s->get($handle);

        return $o instanceof Algorithm ? $o : throw new Fault('not an algorithm');
    }

    private static function roundRobin(mixed $handle, Session $s): RoundRobin
    {
        $o = $s->get($handle);

        return $o instanceof RoundRobin ? $o : throw new Fault('not a round robin');
    }

    private static function balancer(mixed $handle, Session $s): Balancer
    {
        $o = $s->get($handle);

        return $o instanceof Balancer ? $o : throw new Fault('not a balancer');
    }

    private static function group(mixed $handle, Session $s): Group
    {
        $o = $s->get($handle);

        return $o instanceof Group ? $o : throw new Fault('not a group');
    }
}
