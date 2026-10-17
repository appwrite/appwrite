<?php

namespace Tests\Compat\Http;

require_once __DIR__ . '/Capture.php';
require_once __DIR__ . '/Getters.php';
require_once __DIR__ . '/Harness.php';

use Swoole\Http\Request as SwooleRequest;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Compression\Compression;
use Utopia\DI\Container;
use Utopia\Http\Adapter\Swoole\Mode;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Files;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Http\Route;
use Utopia\Http\Router;
use Utopia\Http\TrustedHeaders;
use Utopia\Http\View;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;
use Utopia\Validator\Text;

/**
 * Maps tests/compat/http/spec.json operations onto utopia-php/http.
 * Applications are built from their description and requests are parsed by
 * Swoole and dispatched in process (see Harness.php); glue only.
 */
final class Adapter implements Base, Benchmarks
{
    /** Benchmark inputs, the same as in crates/tools/compat/src/libs/http.rs. */
    private const array ROUTES = [
        ['GET', '/v1/account'],
        ['PATCH', '/v1/account/name'],
        ['POST', '/v1/account/sessions/email'],
        ['GET', '/v1/account/sessions'],
        ['DELETE', '/v1/account/sessions/:sessionId'],
        ['POST', '/v1/account/jwts'],
        ['GET', '/v1/users'],
        ['POST', '/v1/users'],
        ['GET', '/v1/users/:userId'],
        ['DELETE', '/v1/users/:userId'],
        ['GET', '/v1/users/:userId/sessions'],
        ['GET', '/v1/teams'],
        ['POST', '/v1/teams'],
        ['GET', '/v1/teams/:teamId'],
        ['GET', '/v1/teams/:teamId/memberships'],
        ['PATCH', '/v1/teams/:teamId/memberships/:membershipId/status'],
        ['GET', '/v1/databases'],
        ['POST', '/v1/databases'],
        ['GET', '/v1/databases/:databaseId'],
        ['GET', '/v1/databases/:databaseId/collections'],
        ['POST', '/v1/databases/:databaseId/collections'],
        ['GET', '/v1/databases/:databaseId/collections/:collectionId'],
        ['GET', '/v1/databases/:databaseId/collections/:collectionId/attributes'],
        ['POST', '/v1/databases/:databaseId/collections/:collectionId/attributes/string'],
        ['GET', '/v1/databases/:databaseId/collections/:collectionId/documents'],
        ['POST', '/v1/databases/:databaseId/collections/:collectionId/documents'],
        ['GET', self::DOCUMENT_ROUTE],
        ['PATCH', self::DOCUMENT_ROUTE],
        ['DELETE', self::DOCUMENT_ROUTE],
        ['GET', '/v1/storage/buckets'],
        ['GET', '/v1/storage/buckets/:bucketId/files'],
        ['POST', '/v1/storage/buckets/:bucketId/files'],
        ['GET', '/v1/storage/buckets/:bucketId/files/:fileId'],
        ['GET', '/v1/storage/buckets/:bucketId/files/:fileId/view'],
        ['GET', '/v1/storage/buckets/:bucketId/files/:fileId/preview'],
        ['GET', '/v1/functions'],
        ['POST', '/v1/functions/:functionId/executions'],
        ['GET', '/v1/functions/:functionId/executions/:executionId'],
        ['GET', '/v1/health'],
        ['GET', '/v1/locale'],
    ];

    private const string DOCUMENT_ROUTE = '/v1/databases/:databaseId/collections/:collectionId/documents/:documentId';

    private const string DOCUMENT_PATH = '/v1/databases/main/collections/posts/documents/6650c1f2a4b3e9d7c812';

    private const string SESSION = 'eyJpZCI6IjY2NTBjMWYyYTRiM2U5ZDdjODEyIiwic2VjcmV0IjoiNWYzYzlhMWU1ZjNjOWExZSJ9';

    private const int NOW = 1700000000;

    public function benchmarks(): array
    {
        return [
            'match' => ['Match a document path with three params against a router of 40 API routes', function (Session $s) {
                Router::reset();
                foreach (self::ROUTES as [$method, $path]) {
                    Router::addRoute(new Route($method, $path));
                }

                return fn () => Router::match('GET', self::DOCUMENT_PATH)?->params;
            }],
            'request' => ['Parse a JSON POST request (9 headers, a cookie, a query string) and read a header, the cookie, the query and the body params', function (Session $s) {
                $raw = self::post();

                return function () use ($raw) {
                    $swoole = SwooleRequest::create();
                    $swoole->parse($raw);
                    $request = new Request($swoole);

                    return [
                        $request->getHeader('x-appwrite-project'),
                        $request->getCookie('a_session_6650c0de'),
                        $request->getQuery('project'),
                        $request->getParams(),
                    ];
                };
            }],
            'response' => ['Build a 201 response with a header, a session cookie and a JSON user of 18 fields', function (Session $s) {
                $user = self::user();

                // Capture renders each Set-Cookie line as Swoole does.
                return function () use ($user) {
                    $response = new Capture();
                    $response
                        ->setStatusCode(201)
                        ->addHeader('X-Appwrite-Request-Id', '6650c3b9d2e7f4a1c836')
                        ->addCookie('a_session_6650c0de', self::SESSION, self::NOW + 31536000, '/', '.cloud.appwrite.io', true, true, 'None')
                        ->json($user);

                    return $response;
                };
            }],
            'dispatch' => ['Run a GET request through an app of 40 routes: match, validate three path params, an init hook and a JSON action', function (Session $s) {
                Http::reset();
                $server = new TestServer(new Container());
                $http = new Http($server, 'UTC');
                Http::init()
                    ->groups(['api'])
                    ->inject('response')
                    ->action(function (Response $response) {
                        $response->addHeader('X-Content-Type-Options', 'nosniff');
                    });
                foreach (self::ROUTES as [$method, $path]) {
                    $route = Http::addRoute($method, $path);
                    if ($method === 'GET' && $path === self::DOCUMENT_ROUTE) {
                        $route
                            ->groups(['api', 'databases'])
                            ->param('databaseId', '', new Text(36), '')
                            ->param('collectionId', '', new Text(36), '')
                            ->param('documentId', '', new Text(36), '')
                            ->inject('response')
                            ->action(function (string $databaseId, string $collectionId, string $documentId, Response $response) {
                                $response->json([
                                    '$id' => $documentId,
                                    '$collectionId' => $collectionId,
                                    '$databaseId' => $databaseId,
                                    '$createdAt' => '2024-05-24T10:15:00.000+00:00',
                                    '$updatedAt' => '2024-05-24T10:15:00.000+00:00',
                                    '$permissions' => ['read("any")'],
                                    'title' => 'Release notes',
                                    'views' => 1024,
                                ]);
                            });
                    }
                }
                $request = Harness::request(['method' => 'GET', 'uri' => self::DOCUMENT_PATH, 'headers' => [
                    ['Host', 'cloud.appwrite.io'],
                    ['Accept', 'application/json'],
                    ['X-Appwrite-Project', '6650c0de'],
                    ['X-Appwrite-Response-Format', '1.6.0'],
                ]]);

                return function () use ($http, $server, $request) {
                    $server->begin();
                    $response = new Capture();
                    $http->run($request, $response);
                    $server->finish();

                    return $response;
                };
            }],
        ];
    }

    /**
     * A JSON POST as a browser SDK sends it.
     */
    private static function post(): string
    {
        $body = '{"documentId":"unique()","data":{"title":"Release notes","status":"published","views":1024,"tags":["rust","php"]},"permissions":["read(\"any\")","update(\"user:6650c1f2a4b3e9d7c812\")"]}';

        return "POST /v1/databases/main/collections/posts/documents?project=6650c0de HTTP/1.1\r\n"
            . "Host: cloud.appwrite.io\r\n"
            . "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36\r\n"
            . "Accept: application/json\r\n"
            . "Content-Type: application/json\r\n"
            . "Origin: https://app.example.com\r\n"
            . "X-Appwrite-Project: 6650c0de\r\n"
            . "X-Appwrite-Response-Format: 1.6.0\r\n"
            . 'Cookie: a_session_6650c0de=' . self::SESSION . '; a_session_6650c0de_legacy=' . self::SESSION . "\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "\r\n"
            . $body;
    }

    /**
     * A user as the API returns it.
     *
     * @return array<string, mixed>
     */
    private static function user(): array
    {
        return [
            '$id' => '6650c1f2a4b3e9d7c812',
            '$createdAt' => '2024-05-24T10:15:00.000+00:00',
            '$updatedAt' => '2024-05-24T10:15:00.000+00:00',
            'name' => 'Ada Lovelace',
            'registration' => '2024-05-24T10:15:00.000+00:00',
            'status' => true,
            'labels' => ['admin', 'beta'],
            'passwordUpdate' => '2024-05-24T10:15:00.000+00:00',
            'email' => 'ada@example.com',
            'phone' => '+14155550100',
            'emailVerification' => true,
            'phoneVerification' => false,
            'mfa' => false,
            'prefs' => ['theme' => 'dark', 'language' => 'en'],
            'targets' => [],
            'accessedAt' => '2024-05-24T10:15:00.000+00:00',
            'hash' => 'argon2',
            'hashOptions' => ['type' => 'argon2', 'memoryCost' => 7168, 'timeCost' => 5, 'threads' => 1],
        ];
    }

    public function operations(): array
    {
        return [
            'http.run' => fn (array $a, Session $s) => self::run($a),
            'http.start' => fn (array $a, Session $s) => self::start($a),
            'http.mode' => fn (array $a, Session $s) => self::mode($a),
            'http.env' => fn (array $a, Session $s) => Http::getEnv($a['key'], $a['default'] ?? null),
            'request.inspect' => fn (array $a, Session $s) => self::inspect($a),
            'response.run' => fn (array $a, Session $s) => self::respond($a),
            'router.run' => fn (array $a, Session $s) => self::route($a),
            'route.inspect' => fn (array $a, Session $s) => self::routeInspect($a),
            'view.run' => fn (array $a, Session $s) => self::view($a),
            'files.run' => fn (array $a, Session $s) => self::files($a),
            'trusted.new' => function (array $a, Session $s) {
                $t = new TrustedHeaders(...$a);

                return ['ip' => $t->ip, 'proto' => $t->proto];
            },
            'mode.settings' => fn (array $a, Session $s) => self::settings($a['mode']),
            'compression.negotiate' => fn (array $a, Session $s) => Compression::fromAcceptEncoding($a['accept'], $a['supported'] ?? [])?->getContentEncoding(),
        ];
    }

    /**
     * Builds an application.
     *
     * @param array<string, mixed> $app
     * @return array{0: Http, 1: TestServer, 2: list<mixed>}
     */
    private static function build(array $app): array
    {
        Http::reset();
        $resources = new Container();
        $server = new TestServer($resources);
        $http = new Http($server, $app['timezone'] ?? 'UTC');
        Harness::$http = $http;
        Harness::$out = [];
        $setup = [];
        if (isset($app['mode'])) {
            Http::setMode($app['mode']);
        }
        Http::setAllowOverride((bool) ($app['allow_override'] ?? false));
        if (isset($app['compression'])) {
            $http->setCompression(true);
            $http->setCompressionMinSize($app['compression']['min_size'] ?? Http::COMPRESSION_MIN_SIZE_DEFAULT);
            $http->setCompressionSupported($app['compression']['supported'] ?? []);
        }
        foreach ($app['write'] ?? [] as [$path, $content]) {
            Harness::write($path, $content);
        }
        foreach ($app['resources'] ?? [] as $name => $value) {
            $resources->set($name, fn () => $value);
        }
        if (isset($app['files'])) {
            try {
                $http->loadFiles($app['files'][0], $app['files'][1] ?? null);
            } catch (\Throwable $e) {
                $setup[] = Harness::error($e);
            }
        }
        foreach (['init', 'shutdown', 'options', 'error', 'start', 'request'] as $kind) {
            foreach ($app[$kind] ?? [] as $spec) {
                $hook = match ($kind) {
                    'init' => Http::init(),
                    'shutdown' => Http::shutdown(),
                    'options' => Http::options(),
                    'error' => Http::error(),
                    'start' => Http::onStart(),
                    'request' => Http::onRequest(),
                };
                Harness::hook($hook, $spec);
            }
        }
        foreach ($app['routes'] ?? [] as $spec) {
            try {
                $route = isset($spec['method'])
                    ? Http::addRoute($spec['method'], $spec['path'])
                    : Http::routes($spec['methods'] ?? ['GET'], $spec['path']);
                foreach ($spec['aliases'] ?? [] as $alias) {
                    $route->alias($alias);
                }
                Harness::hook($route, $spec);
                $setup[] = true;
            } catch (\Throwable $e) {
                $setup[] = Harness::error($e);
            }
        }
        if (isset($app['wildcard'])) {
            Harness::hook(Http::wildcard(), $app['wildcard']);
        }

        return [$http, $server, $setup];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function run(array $a): array
    {
        [$http, $server, $setup] = self::build((array) ($a['app'] ?? []));
        $telemetry = null;
        if (!empty($a['telemetry'])) {
            $telemetry = new TestTelemetry();
            $http->setTelemetry($telemetry);
        }
        $responses = [];
        foreach ($a['requests'] ?? [] as $spec) {
            Harness::$out = [];
            $request = Harness::request($spec);
            $response = new Capture();
            $server->begin();
            $error = null;
            try {
                if (($spec['via'] ?? 'run') === 'execute') {
                    $http->execute($request, $response);
                } else {
                    $http->run($request, $response);
                }
            } catch (Fault $f) {
                throw $f;
            } catch (\Throwable $e) {
                $error = Harness::error($e);
            }
            $server->finish();
            $result = ['wire' => $response->out(), 'out' => Harness::$out, 'error' => $error];
            if (isset($spec['match'])) {
                $result['match'] = $http->match($request)?->route->getPath();
            }
            if (isset($spec['size'])) {
                $result['size'] = $response->getSize();
            }
            $responses[] = $result;
        }
        Http::reset();
        $out = ['setup' => $setup, 'responses' => $responses];
        if ($telemetry !== null) {
            $values = fn (?object $instrument): array => $instrument === null ? [] : (get_object_vars($instrument)['values'] ?? []);
            $out['telemetry'] = [
                'instruments' => [...array_keys($telemetry->upDownCounters), ...array_keys($telemetry->histograms)],
                'active' => $values($telemetry->upDownCounters['http.server.active_requests'] ?? null),
                'durations' => \count($values($telemetry->histograms['http.server.request.duration'] ?? null)),
                'request_sizes' => $values($telemetry->histograms['http.server.request.body.size'] ?? null),
                'response_sizes' => \count($values($telemetry->histograms['http.server.response.body.size'] ?? null)),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function start(array $a): array
    {
        [$http, , $setup] = self::build((array) ($a['app'] ?? []));
        $error = null;
        try {
            $http->start();
        } catch (Fault $f) {
            throw $f;
        } catch (\Throwable $e) {
            $error = Harness::error($e);
        }
        $out = Harness::$out;
        Http::reset();

        return ['setup' => $setup, 'out' => $out, 'error' => $error];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function mode(array $a): array
    {
        Http::reset();
        if (\array_key_exists('set', $a)) {
            Http::setMode($a['set']);
        }
        $result = ['mode' => Http::getMode(), 'production' => Http::isProduction(), 'development' => Http::isDevelopment(), 'stage' => Http::isStage()];
        Http::reset();

        return $result;
    }

    /**
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function inspect(array $a): array
    {
        $request = Harness::request($a['request'] ?? []);
        $out = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $v = $step[$op];
            match ($op) {
                'get' => $out[] = Getters::request($request, $v),
                'set_header' => $request->setHeader($v[0], $v[1]),
                'add_header' => $request->addHeader($v[0], $v[1]),
                'remove_header' => $request->removeHeader($v),
                'set_query' => $request->setQueryString($v),
                'set_payload' => $request->setPayload($v),
                'set_cookies' => $request->setCookieParams($v),
                'set_server' => $request->setServer($v[0], $v[1]),
                'set_method' => $request->setMethod($v),
                'set_uri' => $request->setURI($v),
                default => throw new Fault("unknown request step {$op}"),
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function respond(array $a): array
    {
        $response = new Capture();
        $env = new Env();
        $env->response = $response;
        Harness::$out = [];
        $error = null;
        try {
            foreach ($a['steps'] ?? [] as $step) {
                if (isset($step['get'])) {
                    Harness::$out[] = Getters::response($response, $step['get']);
                } else {
                    Harness::run([$step], $env);
                }
            }
        } catch (Fault $f) {
            throw $f;
        } catch (\Throwable $e) {
            $error = Harness::error($e);
        }

        return ['wire' => $response->out(), 'out' => Harness::$out, 'error' => $error];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function route(array $a): array
    {
        Router::reset();
        Router::setAllowOverride((bool) ($a['allow_override'] ?? false));
        $routes = [];
        $setup = [];
        foreach ($a['routes'] ?? [] as $spec) {
            try {
                $methods = $spec['methods'] ?? ['GET'];
                if (($spec['via'] ?? 'add') === 'routes') {
                    $route = Http::routes($methods, $spec['path']);
                } else {
                    $route = new Route(\count($methods) === 1 ? $methods[0] : $methods, $spec['path']);
                }
                $routes[] = $route;
                $index = \count($routes) - 1;
                $setup[] = $index;
                if (($spec['via'] ?? 'add') === 'add') {
                    Router::addRoute($route);
                }
            } catch (\Throwable $e) {
                $setup[] = Harness::error($e);
                continue;
            }
            foreach ($spec['aliases'] ?? [] as $alias) {
                try {
                    $route->alias($alias);
                } catch (\Throwable $e) {
                    $setup[] = Harness::error($e);
                }
            }
        }
        if (!empty($a['wildcard'])) {
            $routes[] = $wildcard = new Route('', '');
            Router::setWildcard($wildcard);
        }
        $pairs = $a['match'] ?? [];
        foreach ($a['methods'] ?? [] as $method) {
            foreach ($a['paths'] ?? [] as $path) {
                $pairs[] = [$method, $path];
            }
        }
        $matches = [];
        foreach ($pairs as [$method, $path]) {
            $match = Router::match($method, $path);
            $index = $match === null ? null : array_search($match->route, $routes, true);
            $matches[] = $match === null ? null : ['route' => $index === false ? null : $index, 'params' => $match->params];
        }
        $prepared = [];
        foreach ($a['prepare'] ?? [] as $path) {
            $prepared[] = Router::preparePath($path);
        }
        $table = [];
        foreach (Router::getRoutes() as $method => $templates) {
            $table[$method] = array_map(fn (string|int $t) => (string) $t, array_keys($templates));
        }
        $override = Router::getAllowOverride();
        Router::reset();

        return ['setup' => $setup, 'matches' => $matches, 'prepared' => $prepared, 'routes' => $table, 'allow_override' => $override];
    }

    /**
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function routeInspect(array $a): array
    {
        $route = new Route($a['methods'] ?? 'GET', $a['path'] ?? '/');
        $out = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $v = $step[$op];
            match ($op) {
                'get' => $out[] = Getters::route($route, $v),
                'desc' => $route->desc($v),
                'groups' => $route->groups($v),
                'label' => $route->label($v[0], $v[1]),
                'path' => $route->path($v),
                'hook' => $route->hook($v),
                'param' => $route->param($v[0], $v[1] ?? '', Harness::validator($v[2] ?? ['text' => [10]])),
                'inject' => $route->inject($v),
                default => throw new Fault("unknown route step {$op}"),
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function view(array $a): array
    {
        /** @var array<string, View> $views */
        $views = [];
        $out = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $v = $step[$op];
            if ($op === 'write') {
                Harness::write($v[0], $v[1]);
                continue;
            }
            try {
                $view = $op === 'new' ? null : ($views[$v[0]] ?? throw new Fault("no view {$v[0]}"));
                $result = match ($op) {
                    'new' => (function () use (&$views, $v) {
                        $views[$v[0]] = new View($v[1] ?? '');

                        return;
                    })(),
                    'set_param' => self::none($view->setParam($v[1], $v[2], $v[3] ?? true)),
                    'get_param' => $view->getParam($v[1], $v[2] ?? null),
                    'set_path' => self::none($view->setPath($v[1])),
                    'set_rendered' => self::none($view->setRendered($v[1] ?? true)),
                    'is_rendered' => $view->isRendered(),
                    'print' => $view->print($v[1], $v[2] ?? ''),
                    'render' => $view->render($v[1] ?? true),
                    'exec' => $view->exec(array_map(fn ($name) => $views[$name], $v[1])),
                    'set_parent' => self::none($view->setParent($views[$v[1]])),
                    'parent' => (function () use ($view, $views) {
                        $parent = $view->getParent();

                        return $parent === null ? null : array_search($parent, $views, true);
                    })(),
                    default => throw new Fault("unknown view step {$op}"),
                };
            } catch (Fault $f) {
                throw $f;
            } catch (\Throwable $e) {
                $result = ['$error' => Harness::error($e)];
            }
            if (!\in_array($op, ['new', 'set_param', 'set_path', 'set_rendered', 'set_parent'], true) || \is_array($result)) {
                $out[] = $result;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function files(array $a): array
    {
        $files = new Files();
        $out = [];
        foreach ($a['steps'] ?? [] as $step) {
            $op = (string) array_key_first($step);
            $v = $step[$op];
            if ($op === 'write') {
                Harness::write($v[0], $v[1]);
                continue;
            }
            try {
                $result = match ($op) {
                    'load' => $files->load($v[0], $v[1] ?? null),
                    'count' => $files->getCount(),
                    'is_loaded' => $files->isFileLoaded($v),
                    'contents' => $files->getFileContents($v),
                    'mime' => $files->getFileMimeType($v),
                    'add_mime_type' => $files->addMimeType($v),
                    'remove_mime_type' => $files->removeMimeType($v),
                    'mime_types' => $files->getMimeTypes(),
                    'reset' => $files->reset(),
                    default => throw new Fault("unknown files step {$op}"),
                };
            } catch (Fault $f) {
                throw $f;
            } catch (\Throwable $e) {
                $result = ['$error' => Harness::error($e)];
            }
            $out[] = $result;
        }

        return $out;
    }

    /**
     * A step whose fluent return value is not reported.
     */
    private static function none(mixed $ignored): mixed
    {
        return null;
    }

    /**
     * The settings of a mode that have a meaning outside Swoole.
     *
     * @return array<string, mixed>
     */
    private static function settings(string $mode): array
    {
        $s = ($mode === 'HYPERLOOP_A' ? Mode::HYPERLOOP_A : Mode::HYPERLOOP_B)->settings();

        return [
            'compression' => $s['http_compression'],
            'tcp_nodelay' => $s['open_tcp_nodelay'],
            'tcp_fastopen' => $s['tcp_fastopen'],
            'tcp_defer_accept' => $s['tcp_defer_accept'],
            'reuse_port' => $s['enable_reuse_port'],
            'max_wait_time' => $s['max_wait_time'],
            'max_concurrency' => $s['max_concurrency'],
            'reload_async' => $s['reload_async'],
            'coroutine' => $s['enable_coroutine'],
            'dispatch_mode' => $s['dispatch_mode'],
            'hook_all' => isset($s['hook_flags']) ? $s['hook_flags'] === SWOOLE_HOOK_ALL : null,
            'send_yield' => $s['send_yield'] ?? null,
            'max_request' => $s['max_request'] ?? null,
            'workers_at_least_reactors' => $s['worker_num'] >= $s['reactor_num'],
            'workers_positive' => $s['worker_num'] >= 1,
            'reactors_positive' => $s['reactor_num'] >= 1,
            'aio' => isset($s['aio_worker_num']) ? $s['aio_worker_num'] >= $s['aio_core_worker_num'] : null,
        ];
    }
}
