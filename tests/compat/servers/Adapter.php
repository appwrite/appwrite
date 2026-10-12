<?php

namespace Tests\Compat\Servers;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Http\Harness;
use Tests\Compat\Session;
use Utopia\DI\Container;
use Utopia\Servers\Base as Server;
use Utopia\Servers\Hook;
use Utopia\Validator\Text;

/**
 * Base is abstract: a server whose start() does nothing, exposing prepare().
 */
final class TestServer extends Server
{
    public function start()
    {
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $request
     */
    public function run(Container $context, Hook $hook, array $values, array $request): Container
    {
        return $this->prepare($context, $hook, $values, $request);
    }
}

/**
 * Maps tests/compat/servers/spec.json operations onto utopia-php/servers. Glue only: no logic.
 */
final class Adapter implements Base, Benchmarks
{
    /** @var list<mixed> Values default and validator factories were called with. */
    private static array $out = [];

    public function benchmarks(): array
    {
        return [
            'declare' => ['Declare a hook with three params, two injections, groups and a label', function (Session $s) {
                $text = new Text(128);

                return fn () => (new Hook())
                    ->desc('Create document')
                    ->groups(['api', 'database'])
                    ->label('scope', 'documents.write')
                    ->param('databaseId', '', $text, 'Database ID.')
                    ->param('collectionId', '', $text, 'Collection ID.')
                    ->param('documentId', '', $text, 'Document ID.')
                    ->inject('response')
                    ->inject('dbForProject');
            }],
            'prepare' => ['Resolve and validate three params of a hook from the request', function (Session $s) {
                $server = new TestServer();
                $text = new Text(128);
                $hook = (new Hook())
                    ->param('databaseId', '', $text, 'Database ID.')
                    ->param('collectionId', '', $text, 'Collection ID.')
                    ->param('documentId', '', $text, 'Document ID.', true);
                $context = new Container();
                $request = ['databaseId' => 'main', 'collectionId' => 'users'];

                return fn () => $server->run($context, $hook, [], $request);
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'hook.run' => fn (array $a, Session $s) => self::hook($a),
            'base.prepare' => fn (array $a, Session $s) => self::prepare($a),
            'base.run' => fn (array $a, Session $s) => self::base($a),
        ];
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function param(Hook $hook, array $p): void
    {
        $default = $p['default'] ?? null;
        if (!empty($p['default_fn'])) {
            $value = $default;
            $default = function (...$deps) use ($value) {
                foreach ($deps as $dep) {
                    self::$out[] = $dep;
                }

                return $value;
            };
        }
        $spec = $p['validator'] ?? ['any' => true];
        if (isset($spec['factory'])) {
            $inner = $spec['factory'];
            $validator = function (...$deps) use ($inner) {
                foreach ($deps as $dep) {
                    self::$out[] = $dep;
                }

                return Harness::validator($inner);
            };
        } else {
            $validator = Harness::validator($spec);
        }
        $enum = null;
        if (\array_key_exists('enum', $p)) {
            $enum = (object) $p['enum'];
        }
        $hook->param(
            $p['key'],
            $default,
            $validator,
            $p['description'] ?? '',
            (bool) ($p['optional'] ?? false),
            $p['injections'] ?? [],
            (bool) ($p['skip_validation'] ?? false),
            (bool) ($p['deprecated'] ?? false),
            $p['example'] ?? '',
            $p['model'] ?? null,
            $p['aliases'] ?? [],
            $enum,
        );
    }

    /**
     * The params of a hook, with the validator described.
     *
     * @return array<string, mixed>
     */
    private static function params(Hook $hook): array
    {
        $out = [];
        foreach ($hook->getParams() as $key => $param) {
            $out[$key] = [
                'default' => $param['default'] instanceof \Closure ? 'factory' : $param['default'],
                'validator' => $param['validator'] instanceof \Closure ? 'factory' : $param['validator']->getDescription(),
                'description' => $param['description'],
                'optional' => $param['optional'],
                'injections' => $param['injections'],
                'skipValidation' => $param['skipValidation'],
                'deprecated' => $param['deprecated'],
                'example' => $param['example'],
                'model' => $param['model'],
                'aliases' => $param['aliases'],
                'enum' => $param['enum'] === null ? null : (array) $param['enum'],
                'value' => $param['value'],
                'order' => $param['order'],
            ];
        }

        return $out;
    }

    private static function error(\Throwable $e): array
    {
        return ['$error' => ['class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode()]];
    }

    /**
     * A hook configured and read by steps.
     *
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function hook(array $a): array
    {
        $hook = new Hook();
        $results = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $x = $step[$op];
            try {
                $results[] = match ($op) {
                    'desc' => $hook->desc($x) === $hook,
                    'groups' => $hook->groups($x) === $hook,
                    'label' => $hook->label($x['key'], $x['value']) === $hook,
                    'param' => (function () use ($hook, $x) {
                        self::param($hook, $x);

                        return true;
                    })(),
                    'inject' => $hook->inject($x) === $hook,
                    'action' => $hook->action(fn () => $x) === $hook,
                    'set_param_value' => $hook->setParamValue($x['key'], $x['value']) === $hook,
                    'get' => match ($x) {
                        'desc' => $hook->getDesc(),
                        'groups' => $hook->getGroups(),
                        'params' => self::params($hook),
                        'injections' => $hook->getInjections(),
                        'dependencies' => $hook->getDependencies(),
                        'params_values' => $hook->getParamsValues(),
                        'action' => (function () use ($hook) { ($hook->getAction())(); return true; })(),
                        default => throw new Fault("unknown getter {$x}"),
                    },
                    'label_of' => $hook->getLabel($x['key'], $x['default'] ?? null),
                    'param_value' => $hook->getParamValue($x),
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
     * prepare(): a hook's params resolved from `values` and `request`, with
     * `resources` in the context.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function prepare(array $a): array
    {
        Server::reset();
        self::$out = [];
        $server = new TestServer();
        $context = new Container();
        foreach ((array) ($a['resources'] ?? []) as $name => $value) {
            $context->set($name, fn () => $value);
        }
        $hook = new Hook();
        foreach ($a['params'] ?? [] as $p) {
            self::param($hook, $p);
        }
        $error = null;
        $scope = [];
        try {
            $container = $server->run($context, $hook, (array) ($a['values'] ?? []), (array) ($a['request'] ?? []));
            foreach (array_keys($hook->getParams()) as $key) {
                $scope[$key] = $container->get($key);
            }
        } catch (Fault $f) {
            throw $f;
        } catch (\Throwable $e) {
            $error = self::error($e)['$error'];
        }

        return ['scope' => $scope, 'values' => $hook->getParamsValues(), 'factories' => self::$out, 'error' => $error];
    }

    /**
     * Base's mode, hooks and container, driven by steps.
     *
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function base(array $a): array
    {
        Server::reset();
        $server = new TestServer();
        $results = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $x = $step[$op];
            try {
                $results[] = match ($op) {
                    'set_mode' => Server::setMode($x),
                    'mode' => [Server::getMode(), Server::isProduction(), Server::isDevelopment(), Server::isStage()],
                    'hook' => match ($x) {
                        'init' => Server::init()->getGroups(),
                        'shutdown' => Server::shutdown()->getGroups(),
                        'error' => Server::error()->getGroups(),
                        'start' => Server::onStart()->getGroups(),
                        'end' => Server::onEnd()->getGroups(),
                        default => throw new Fault("unknown hook {$x}"),
                    },
                    'set_container' => (function () use ($server, $x) {
                        $container = new Container();
                        $container->set('name', fn () => $x);

                        return $server->setContainer($container) === $server;
                    })(),
                    'container' => $server->getContainer()->get('name'),
                    'reset' => Server::reset(),
                    default => throw new Fault("unknown step {$op}"),
                };
            } catch (Fault $f) {
                throw $f;
            } catch (\Throwable $e) {
                $results[] = ['$error' => ['class' => $e::class]];
            }
        }
        Server::reset();

        return $results;
    }
}
