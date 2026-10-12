<?php

namespace Tests\Compat\Platform;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Http\Capture;
use Tests\Compat\Http\Harness;
use Tests\Compat\Http\TestServer as HttpServer;
use Tests\Compat\Session;
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;
use Utopia\DI\Container;
use Utopia\Http\Http;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Module;
use Utopia\Platform\Platform;
use Utopia\Platform\Service;
use Utopia\Queue\Adapter as QueueAdapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;
use Utopia\Servers\Hook;
use Utopia\Validator\Text;

/** Action, Service, Module and Platform are abstract: these add nothing. */
final class TestAction extends Action
{
}

final class TestService extends Service
{
    public function __construct(string $type)
    {
        $this->type = $type;
        $this->actions = [];
    }
}

final class TestModule extends Module
{
}

final class TestPlatform extends Platform
{
}

/** A consumer worker initialization never receives from. */
final class FakeConsumer implements Consumer
{
    public function receive(Queue $queue, int $timeout, int $n = 1): never
    {
        throw new Fault('worker initialization must not receive messages');
    }

    public function commit(Queue $queue, Message $message): void
    {
    }

    public function reject(Queue $queue, Message $message): void
    {
    }

    public function close(): void
    {
    }
}

/** A queue adapter that never starts (as the platform's InitWorkerTest). */
final class RecordingAdapter extends QueueAdapter
{
    public function __construct()
    {
        parent::__construct(static fn (string $q): Consumer => new FakeConsumer(), 1);
    }

    public function start(): self
    {
        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    public function workerStart(callable $callback): self
    {
        return $this;
    }

    public function workerStop(callable $callback): self
    {
        return $this;
    }
}

/**
 * Maps tests/compat/platform/spec.json operations onto utopia-php/platform. Glue only: no logic.
 */
final class Adapter implements Base, Benchmarks
{
    /** @var list<mixed> What task and worker callbacks were called with, in order. */
    private static array $calls = [];

    public function benchmarks(): array
    {
        return [
            'declare' => ['Declare an action: method, path, description, groups, a label, three params and two injections', function (Session $s) {
                $text = new Text(128);

                return fn () => (new TestAction())
                    ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
                    ->setHttpPath('/v1/databases/:databaseId/collections')
                    ->desc('Create collection')
                    ->groups(['api', 'database'])
                    ->label('scope', 'collections.write')
                    ->param('databaseId', '', $text, 'Database ID.')
                    ->param('collectionId', '', $text, 'Collection ID.')
                    ->param('name', '', $text, 'Collection name.')
                    ->inject('response')
                    ->inject('dbForProject')
                    ->callback(fn () => null);
            }],
            'init' => ['Register a service of ten HTTP actions with two params and an injection each', function (Session $s) {
                $text = new Text(128);
                $service = new TestService(Service::TYPE_HTTP);
                for ($i = 0; $i < 10; $i++) {
                    $service->addAction("action{$i}", (new TestAction())
                        ->setHttpMethod('GET')
                        ->setHttpPath("/v1/bench/{$i}/:id")
                        ->groups(['api'])
                        ->param('id', '', $text, 'ID.')
                        ->param('search', '', $text, 'Search.', true)
                        ->inject('response')
                        ->callback(fn () => null));
                }
                $platform = new TestPlatform(new TestModule());
                $platform->addService('bench', $service);

                return function () use ($platform) {
                    Http::reset();
                    $platform->init(Service::TYPE_HTTP);
                };
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'action.run' => fn (array $a, Session $s) => self::action($a),
            'service.run' => fn (array $a, Session $s) => self::service($a),
            'platform.run' => fn (array $a, Session $s) => self::platform($a),
        ];
    }

    private static function error(\Throwable $e): array
    {
        return ['$error' => ['class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode()]];
    }

    /**
     * A callback for a service of `$type`: HTTP runs Harness statements, any
     * other records [id, its arguments].
     *
     * @param array<string, mixed> $action
     */
    private static function callback(string $type, array $action): callable
    {
        $spec = $action['callback'];
        if ($type === Service::TYPE_HTTP) {
            $hook = new Hook();
            Harness::hook($hook, ['params' => $action['params'] ?? [], 'inject' => $action['inject'] ?? [], 'action' => $spec['statements'] ?? []]);

            return $hook->getAction();
        }
        $id = $spec['id'] ?? '';
        $fail = $spec['fail'] ?? null;

        return function (...$args) use ($id, $fail) {
            self::$calls[] = [$id, array_map(fn ($v) => $v instanceof \Throwable ? ['$exception' => self::error($v)['$error']] : $v, $args)];
            if ($fail !== null) {
                throw new \Exception($fail['message'] ?? '', $fail['code'] ?? 0);
            }
        };
    }

    /**
     * A param's default and validator: `default_fn` and `{factory: spec}`
     * record what they are called with in Harness::$out.
     *
     * @param array<string, mixed> $p
     * @return array{0: mixed, 1: mixed}
     */
    private static function rule(array $p): array
    {
        $default = $p['default'] ?? null;
        if (!empty($p['default_fn'])) {
            $value = $default;
            $default = function (...$deps) use ($value) {
                foreach ($deps as $dep) {
                    Harness::$out[] = $dep;
                }

                return $value;
            };
        }
        $spec = $p['validator'] ?? ['any' => true];
        if (isset($spec['factory'])) {
            $inner = $spec['factory'];
            $validator = function (...$deps) use ($inner) {
                foreach ($deps as $dep) {
                    Harness::$out[] = $dep;
                }

                return Harness::validator($inner);
            };
        } else {
            $validator = Harness::validator($spec);
        }

        return [$default, $validator];
    }

    /**
     * Applies one declaration step to an action.
     */
    private static function declare(Action $action, string $op, mixed $x): mixed
    {
        return match ($op) {
            'set_type' => $action->setType($x) === $action,
            'desc' => $action->desc($x) === $action,
            'groups' => $action->groups($x) === $action,
            'label' => $action->label($x['key'], $x['value']) === $action,
            'param' => (function () use ($action, $x) {
                [$default, $validator] = self::rule($x);
                $e = isset($x['enum']) ? (array) $x['enum'] : null;
                $enum = $e === null ? null : new Enum($e['name'] ?? null, isset($e['map']) ? (array) $e['map'] : null, $e['exclude'] ?? null);

                return $action->param(
                    $x['key'],
                    $default,
                    $validator,
                    $x['description'] ?? '',
                    (bool) ($x['optional'] ?? false),
                    $x['injections'] ?? [],
                    (bool) ($x['skip_validation'] ?? false),
                    (bool) ($x['deprecated'] ?? false),
                    $x['example'] ?? '',
                    $x['aliases'] ?? [],
                    $enum,
                ) === $action;
            })(),
            'inject' => $action->inject($x) === $action,
            'set_http_path' => $action->setHttpPath($x) === $action,
            'set_http_method' => $action->setHttpMethod($x) === $action,
            'set_http_methods' => $action->setHttpMethods($x) === $action,
            'http_alias' => $action->httpAlias($x) === $action,
            default => throw new Fault("unknown step {$op}"),
        };
    }

    /**
     * An action built from its description (as platform.run's ACTION).
     *
     * @param array<string, mixed> $spec
     */
    private static function build(string $type, array|\stdClass $spec): Action
    {
        $spec = (array) $spec;
        $action = new TestAction();
        if (isset($spec['type'])) {
            $action->setType($spec['type']);
        }
        if (isset($spec['desc'])) {
            $action->desc($spec['desc']);
        }
        if (isset($spec['groups'])) {
            $action->groups($spec['groups']);
        }
        $http = (array) ($spec['http'] ?? []);
        if (isset($http['method'])) {
            $action->setHttpMethod($http['method']);
        }
        if (isset($http['methods'])) {
            $action->setHttpMethods($http['methods']);
        }
        if (isset($http['path'])) {
            $action->setHttpPath($http['path']);
        }
        foreach ($http['aliases'] ?? [] as $alias) {
            $action->httpAlias($alias);
        }
        foreach ($spec['params'] ?? [] as $p) {
            self::declare($action, 'param', $p);
        }
        foreach ($spec['inject'] ?? [] as $name) {
            $action->inject($name);
        }
        foreach ($spec['labels'] ?? [] as $key => $value) {
            $action->label($key, $value);
        }
        if (isset($spec['callback'])) {
            $action->callback(self::callback($type, $spec));
        }

        return $action;
    }

    /**
     * A param as JSON: callables as "factory", the validator described, the enum as its fields.
     *
     * @param array<string, mixed> $param
     * @return array<string, mixed>
     */
    private static function param(array $param): array
    {
        $out = [];
        foreach (['default', 'validator', 'description', 'optional', 'injections', 'skipValidation', 'deprecated', 'example', 'model', 'aliases', 'enum'] as $key) {
            if (!\array_key_exists($key, $param)) {
                continue;
            }
            $v = $param[$key];
            $out[$key] = match ($key) {
                'default' => $v instanceof \Closure ? 'factory' : $v,
                'validator' => $v instanceof \Closure ? 'factory' : $v->getDescription(),
                'enum' => $v === null ? null : ['name' => $v->name, 'map' => $v->map, 'exclude' => $v->exclude],
                default => $v,
            };
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Action $action): array
    {
        $params = [];
        foreach ($action->getParams() as $key => $p) {
            $params[$key] = self::param($p);
        }
        $options = [];
        foreach ($action->getOptions() as $key => $option) {
            $options[] = [$key, $option['type']];
        }
        try {
            $path = $action->getHttpPath();
        } catch (\TypeError $e) {
            $path = self::error($e);
        }

        return [
            'type' => $action->getType(),
            'desc' => $action->getDesc(),
            'groups' => $action->getGroups(),
            'callback' => $action->getCallback() !== null,
            'params' => $params,
            'injections' => $action->getInjections(),
            'labels' => $action->getLabels(),
            'options' => $options,
            'path' => $path,
            'method' => $action->getHttpMethod(),
            'methods' => $action->getHttpMethods(),
            'aliases' => $action->getHttpAliases(),
        ];
    }

    /**
     * new Action() driven by steps (declarations, or `get`).
     *
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function action(array $a): array
    {
        Harness::$out = [];
        $action = new TestAction();
        $results = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $x = $step[$op];
            try {
                $results[] = $op === 'get' ? self::describe($action) : self::declare($action, $op, $x);
            } catch (Fault $f) {
                throw $f;
            } catch (\Throwable $e) {
                $results[] = self::error($e);
            }
        }

        return $results;
    }

    /**
     * A service of `type` driven by steps: set_type, type, add_action {key, action}, remove_action, action key, actions.
     *
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function service(array $a): array
    {
        $service = new TestService($a['type'] ?? Service::TYPE_HTTP);
        $results = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $x = $step[$op];
            $results[] = match ($op) {
                'set_type' => $service->setType($x) === $service,
                'type' => $service->getType(),
                'add_action' => $service->addAction($x['key'], self::build($service->getType(), $x['action'] ?? [])) === $service,
                'remove_action' => $service->removeAction($x) === $service,
                'action' => ($found = $service->getAction($x)) === null ? null : self::describe($found),
                'actions' => array_keys($service->getActions()),
                default => throw new Fault("unknown step {$op}"),
            };
        }

        return $results;
    }

    /**
     * A module from its description: services added in order.
     *
     * @param array<string, mixed> $spec
     */
    private static function module(array|\stdClass $spec): Module
    {
        $spec = (array) $spec;
        $module = new TestModule();
        foreach ($spec['services'] ?? [] as $key => $s) {
            $module->addService($key, self::serviceOf($s));
        }

        return $module;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function serviceOf(array|\stdClass $spec): Service
    {
        $spec = (array) $spec;
        $service = new TestService($spec['type'] ?? Service::TYPE_HTTP);
        foreach ($spec['actions'] ?? [] as $key => $action) {
            $service->addAction($key, self::build($service->getType(), $action));
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function initParams(array $params): array
    {
        $out = [];
        foreach (['workerName', 'workers', 'jobs', 'queueName', 'workersNum', 'namespace'] as $key) {
            if (\array_key_exists($key, $params)) {
                $out[$key] = $key === 'jobs' ? array_map(fn ($job) => (array) $job, (array) $params[$key]) : $params[$key];
            }
        }
        if (!empty($params['consumer'])) {
            $out['consumerFactory'] = static fn (string $q): Consumer => new FakeConsumer();
        }

        return $out;
    }

    /**
     * Labels by key, as getLabel() reads them (null when unset).
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function labels(Hook $hook, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $hook->getLabel($key, null);
        }

        return $out;
    }

    /**
     * A platform whose core module is `core`, driven by steps.
     *
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function platform(array $a): array
    {
        Http::reset();
        Harness::$out = [];
        self::$calls = [];
        $server = new HttpServer(new Container());
        $http = new Http($server, 'UTC');
        Harness::$http = $http;
        $platform = new TestPlatform(self::module($a['core'] ?? []));
        $results = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $x = $step[$op];
            try {
                $results[] = match ($op) {
                    'add_module' => $platform->addModule(self::module($x)) === $platform,
                    'add_service' => $platform->addService($x['key'], self::serviceOf($x['service'] ?? [])) === $platform,
                    'remove_service' => $platform->removeService($x) === $platform,
                    'service' => (function () use ($platform, $x) {
                        $service = $platform->getService($x);

                        return ['type' => $service->getType(), 'actions' => array_keys($service->getActions())];
                    })(),
                    'services' => array_keys($platform->getServices()),
                    'set_cli' => $platform->setCli(new CLI(new Generic(), $x)) === $platform,
                    'set_worker' => $platform->setWorker(new Server(new RecordingAdapter())) === $platform,
                    'init' => $platform->init($x['type'], self::initParams((array) ($x['params'] ?? []))),
                    'cli' => (function () use ($platform, $x) {
                        if (!(new \ReflectionProperty(Platform::class, 'cli'))->isInitialized($platform)) {
                            return null;
                        }
                        $tasks = [];
                        foreach ($platform->getCli()->getTasks() as $key => $task) {
                            $params = [];
                            foreach ($task->getParams() as $k => $p) {
                                $params[$k] = self::param($p);
                            }
                            $tasks[] = ['key' => $key, 'name' => $task->getName(), 'desc' => $task->getDesc(), 'groups' => $task->getGroups(), 'params' => $params, 'dependencies' => $task->getDependencies(), 'labels' => self::labels($task, $x)];
                        }

                        return ['tasks' => $tasks, 'args' => $platform->getCli()->getArgs()];
                    })(),
                    'run_cli' => (function () use ($platform) {
                        if (!(new \ReflectionProperty(Platform::class, 'cli'))->isInitialized($platform)) {
                            return null;
                        }
                        self::$calls = [];
                        $error = null;
                        try {
                            $platform->getCli()->run();
                        } catch (Fault $f) {
                            throw $f;
                        } catch (\Throwable $e) {
                            $error = self::error($e)['$error'];
                        }

                        return ['calls' => self::$calls, 'error' => $error];
                    })(),
                    'worker' => (function () use ($platform, $x) {
                        if (!(new \ReflectionProperty(Platform::class, 'worker'))->isInitialized($platform)) {
                            return null;
                        }
                        $worker = $platform->getWorker();
                        $jobs = [];
                        foreach ($worker->jobs() as $queue => $job) {
                            $jobs[] = ['queue' => $queue, 'coroutines' => $worker->coroutines($queue), 'prefetch' => $worker->prefetch($queue), 'desc' => $job->getDesc(), 'groups' => $job->getGroups(), 'params' => array_keys($job->getParams()), 'dependencies' => $job->getDependencies(), 'labels' => self::labels($job, $x)];
                        }
                        $hooks = fn (array $list) => array_map(fn (Hook $h) => ['desc' => $h->getDesc(), 'groups' => $h->getGroups(), 'params' => array_keys($h->getParams()), 'dependencies' => $h->getDependencies(), 'labels' => self::labels($h, $x)], $list);

                        return ['jobs' => $jobs, 'start' => $hooks($worker->getWorkerStart()), 'stop' => $hooks($worker->getWorkerStop())];
                    })(),
                    'worker_hooks' => (function () use ($platform, $x) {
                        if (!(new \ReflectionProperty(Platform::class, 'worker'))->isInitialized($platform)) {
                            return null;
                        }
                        self::$calls = [];
                        $worker = $platform->getWorker();
                        foreach ($x === 'start' ? $worker->getWorkerStart() : $worker->getWorkerStop() as $hook) {
                            ($hook->getAction())();
                        }

                        return self::$calls;
                    })(),
                    'routes' => (function () use ($x) {
                        $routes = [];
                        foreach (Http::getRoutes() as $method => $list) {
                            foreach ($list as $template => $route) {
                                $params = [];
                                foreach ($route->getParams() as $k => $p) {
                                    $params[$k] = self::param($p);
                                }
                                $routes[] = ['method' => $method, 'template' => (string) $template, 'path' => $route->getPath(), 'methods' => $route->getMethods(), 'desc' => $route->getDesc(), 'groups' => $route->getGroups(), 'params' => $params, 'dependencies' => $route->getDependencies(), 'labels' => self::labels($route, $x)];
                            }
                        }

                        return $routes;
                    })(),
                    'request' => (function () use ($http, $server, $x) {
                        Harness::$out = [];
                        $request = Harness::request($x);
                        $response = new Capture();
                        $server->begin();
                        $error = null;
                        try {
                            $http->run($request, $response);
                        } catch (Fault $f) {
                            throw $f;
                        } catch (\Throwable $e) {
                            $error = Harness::error($e);
                        }
                        $server->finish();

                        return ['wire' => $response->out(), 'out' => Harness::$out, 'error' => $error];
                    })(),
                    default => throw new Fault("unknown step {$op}"),
                };
            } catch (Fault $f) {
                throw $f;
            } catch (\Throwable $e) {
                $results[] = self::error($e);
            }
        }
        Http::reset();

        return $results;
    }
}
