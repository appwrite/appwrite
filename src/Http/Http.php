<?php

namespace Utopia\Http;

use Utopia\DI\Container;
use Utopia\Servers\Hook;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\UpDownCounter;
use Utopia\Validator;

class Http
{
    public const int COMPRESSION_MIN_SIZE_DEFAULT = 1024;
    public const int COMPRESSION_BROTLI_LEVEL_DEFAULT = 4;
    public const int COMPRESSION_ZSTD_LEVEL_DEFAULT = 3;

    /**
     * Request method constants
     */
    public const string REQUEST_METHOD_GET = 'GET';

    public const string REQUEST_METHOD_POST = 'POST';

    public const string REQUEST_METHOD_PUT = 'PUT';

    public const string REQUEST_METHOD_PATCH = 'PATCH';

    public const string REQUEST_METHOD_DELETE = 'DELETE';

    public const string REQUEST_METHOD_OPTIONS = 'OPTIONS';

    public const string REQUEST_METHOD_HEAD = 'HEAD';

    /**
     * Mode Type
     */
    public const string MODE_TYPE_DEVELOPMENT = 'development';

    public const string MODE_TYPE_STAGE = 'stage';

    public const string MODE_TYPE_PRODUCTION = 'production';


    /**
     * Current running mode
     */
    protected static string $mode = '';

    /**
     * Errors
     *
     * Errors callbacks
     *
     * @var Hook[]
     */
    protected static array $errors = [];

    /**
     * Init
     *
     * A callback function that is initialized on application start
     *
     * @var Hook[]
     */
    protected static array $init = [];

    /**
     * Shutdown
     *
     * A callback function that is initialized on application end
     *
     * @var Hook[]
     */
    protected static array $shutdown = [];

    /**
     * Options
     *
     * A callback function for options method requests
     *
     * @var Hook[]
     */
    protected static array $options = [];

    /**
     * Server Start hooks
     *
     * @var Hook[]
     */
    protected static array $startHooks = [];

    /**
     * Request hooks
     *
     * @var Hook[]
     */
    protected static array $requestHooks = [];

    /**
     * Compression
     */
    protected bool $compression = false;

    protected int $compressionMinSize = Http::COMPRESSION_MIN_SIZE_DEFAULT;

    protected mixed $compressionSupported = [];

    private Histogram $requestDuration;

    private UpDownCounter $activeRequests;

    private Histogram $requestBodySize;

    private Histogram $responseBodySize;

    /**
     * Http
     */
    public function __construct(
        protected Adapter $adapter,
        string $timezone,
        protected Files $files = new Files(),
    ) {
        date_default_timezone_set($timezone);
        $this->setTelemetry(new NoTelemetry());
    }

    /**
     * Set telemetry adapter.
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        // Let the adapter publish its own runtime metrics (e.g. Swoole worker
        // stats); a no-op for adapters without them.
        $this->adapter->setTelemetry($telemetry);

        // https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpserverrequestduration
        $this->requestDuration = $telemetry->createHistogram(
            'http.server.request.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]],
        );

        // https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpserveractive_requests
        $this->activeRequests = $telemetry->createUpDownCounter('http.server.active_requests', '{request}');
        // https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpserverrequestbodysize
        $this->requestBodySize = $telemetry->createHistogram('http.server.request.body.size', 'By');
        // https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpserverresponsebodysize
        $this->responseBodySize = $telemetry->createHistogram('http.server.response.body.size', 'By');
    }

    /**
     * Set Compression
     */
    public function setCompression(bool $compression): void
    {
        $this->compression = $compression;
    }

    /**
     * Set minimum compression size
     */
    public function setCompressionMinSize(int $compressionMinSize): void
    {
        $this->compressionMinSize = $compressionMinSize;
    }

    /**
     * Set supported compression algorithms
     */
    public function setCompressionSupported(mixed $compressionSupported): void
    {
        $this->compressionSupported = $compressionSupported;
    }

    /**
     * GET
     *
     * Add GET request route
     */
    public static function get(string $url): Route
    {
        return self::routes(self::REQUEST_METHOD_GET, $url);
    }

    /**
     * POST
     *
     * Add POST request route
     */
    public static function post(string $url): Route
    {
        return self::routes(self::REQUEST_METHOD_POST, $url);
    }

    /**
     * PUT
     *
     * Add PUT request route
     */
    public static function put(string $url): Route
    {
        return self::routes(self::REQUEST_METHOD_PUT, $url);
    }

    /**
     * PATCH
     *
     * Add PATCH request route
     */
    public static function patch(string $url): Route
    {
        return self::routes(self::REQUEST_METHOD_PATCH, $url);
    }

    /**
     * DELETE
     *
     * Add DELETE request route
     */
    public static function delete(string $url): Route
    {
        return self::routes(self::REQUEST_METHOD_DELETE, $url);
    }

    /**
     * ROUTES
     *
     * Add one route under one or more request methods
     *
     * @param string|array<int, string> $methods
     */
    public static function routes(string|array $methods, string $url): Route
    {
        $methods = \is_array($methods) ? $methods : [$methods];
        $methods = array_values(array_unique($methods));

        if (empty($methods)) {
            throw new \Exception('At least one HTTP method is required.');
        }

        $routes = Router::getRoutes();

        foreach ($methods as $method) {
            if (!\array_key_exists($method, $routes)) {
                throw new \Exception("Method ({$method}) not supported.");
            }
        }

        $route = new Route($methods, $url);
        Router::addRoute($route);

        return $route;
    }

    /**
     * Wildcard
     *
     * Add Wildcard route
     */
    public static function wildcard(): Route
    {
        $route = new Route('', '');
        Router::setWildcard($route);

        return $route;
    }

    /**
     * Init
     *
     * Set a callback function that will be initialized on application start
     */
    public static function init(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);

        self::$init[] = $hook;

        return $hook;
    }

    /**
     * Shutdown
     *
     * Set a callback function that will be initialized on application end
     */
    public static function shutdown(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);

        self::$shutdown[] = $hook;

        return $hook;
    }

    /**
     * Options
     *
     * Set a callback function for all request with options method
     */
    public static function options(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);

        self::$options[] = $hook;

        return $hook;
    }

    /**
     * Error
     *
     * An error callback for failed or no matched requests
     */
    public static function error(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);

        self::$errors[] = $hook;

        return $hook;
    }

    /**
     * Get env var
     *
     * Method for querying env varialbles. If $key is not found $default value will be returned.
     */
    public static function getEnv(string $key, ?string $default = null): ?string
    {
        return $_SERVER[$key] ?? $default;
    }

    /**
     * Get Mode
     *
     * Get current mode
     */
    public static function getMode(): string
    {
        return self::$mode;
    }

    /**
     * Set Mode
     *
     * Set current mode
     */
    public static function setMode(string $value): void
    {
        self::$mode = $value;
    }

    /**
     * Get allow override
     *
     */
    public static function getAllowOverride(): bool
    {
        return Router::getAllowOverride();
    }

    /**
     * Set Allow override
     *
     */
    public static function setAllowOverride(bool $value): void
    {
        Router::setAllowOverride($value);
    }

    /**
     * Static resources container.
     *
     * Shortcut for the underlying adapter's {@see Adapter::resources()}. Use
     * `$http->resources()->set(...)` to register app-wide services that are
     * shared across every request for the lifetime of the server.
     */
    public function resources(): Container
    {
        return $this->adapter->resources();
    }

    /**
     * Per-request context container.
     *
     * Shortcut for the underlying adapter's {@see Adapter::context()}. Use
     * `$http->context()->set(...)` to register request-scoped resources and
     * `$http->context()->get(...)` to read them. Lookups fall through to the
     * static resources container, so app-wide services remain accessible.
     */
    public function context(): Container
    {
        return $this->adapter->context();
    }

    /**
     * Is http in production mode?
     */
    public static function isProduction(): bool
    {
        return self::MODE_TYPE_PRODUCTION === self::$mode;
    }

    /**
     * Is http in development mode?
     */
    public static function isDevelopment(): bool
    {
        return self::MODE_TYPE_DEVELOPMENT === self::$mode;
    }

    /**
     * Is http in stage mode?
     */
    public static function isStage(): bool
    {
        return self::MODE_TYPE_STAGE === self::$mode;
    }

    /**
     * Get Routes
     *
     * Get all application routes
     *
     * @return array<string, Route[]>
     */
    public static function getRoutes(): array
    {
        return Router::getRoutes();
    }

    /**
     * Add Route
     *
     * Add routing route method, path and callback
     */
    public static function addRoute(string $method, string $url): Route
    {
        $route = new Route($method, $url);

        Router::addRoute($route);

        return $route;
    }

    /**
     * Load directory.
     *
     *
     * @throws \Exception
     */
    public function loadFiles(string $directory, ?string $root = null): void
    {
        $this->files->load($directory, $root);
    }

    /**
     * Is file loaded.
     */
    protected function isFileLoaded(string $uri): bool
    {
        return $this->files->isFileLoaded($uri);
    }

    /**
     * Get file contents.
     *
     * @return string
     * @throws \Exception
     */
    protected function getFileContents(string $uri): mixed
    {
        return $this->files->getFileContents($uri);
    }

    /**
     * Get file MIME type.
     *
     * @return string
     * @throws \Exception
     */
    protected function getFileMimeType(string $uri): mixed
    {
        return $this->files->getFileMimeType($uri);
    }

    public static function onStart(): Hook
    {
        $hook = new Hook();
        self::$startHooks[] = $hook;
        return $hook;
    }

    public static function onRequest(): Hook
    {
        $hook = new Hook();
        self::$requestHooks[] = $hook;
        return $hook;
    }

    public function start(): void
    {

        $this->adapter->onRequest(
            fn(Request $request, Response $response) => $this->run($request, $response),
        );

        $this->adapter->onStart(function ($server) {
            $this->resources()->set('server', fn() => $server);
            try {

                foreach (self::$startHooks as $hook) {
                    $arguments = $this->getArguments($hook, [], []);
                    \call_user_func_array($hook->getAction(), $arguments);
                }
            } catch (\Exception $e) {
                $this->resources()->set('error', fn() => $e);

                foreach (self::$errors as $error) { // Global error hooks
                    if (\in_array('*', $error->getGroups())) {
                        try {
                            $arguments = $this->getArguments($error, [], []);
                            \call_user_func_array($error->getAction(), $arguments);
                        } catch (\Throwable $hookError) {
                            throw new Exception('Error handler had an error: ' . $hookError::class . ': ' . $hookError->getMessage(), 500, $e);
                        }
                    }
                }
            }
        });

        $this->adapter->start();
    }

    /**
     * Find the route registered for the given request, or null if none match.
     */
    public function match(Request $request): ?RouteMatch
    {
        $url = parse_url($request->getURI(), PHP_URL_PATH);
        $url = \is_string($url) ? ($url === '' ? '/' : $url) : '/';
        $method = $request->getMethod();
        $method = (self::REQUEST_METHOD_HEAD === $method) ? self::REQUEST_METHOD_GET : $method;

        return Router::match($method, $url);
    }

    /**
     * Match a request and run its route's handler and hooks.
     *
     * HEAD runs as GET with the response body suppressed. OPTIONS fires
     * options hooks and returns without dispatching. An unmatched request
     * fires global error hooks with a 404.
     *
     * This is a re-entrant dispatch primitive — safe to call from inside
     * another handler with a synthesized Request/Response (e.g. a GraphQL
     * resolver invoking an API route). It does not run request-level setup
     * (compression, request hooks, telemetry); those belong to {@see run()},
     * which is the entry point for top-level requests from the server.
     */
    public function execute(Request $request, Response $response): static
    {
        $method = $request->getMethod();

        if (self::REQUEST_METHOD_HEAD === $method) {
            $method = self::REQUEST_METHOD_GET;
            $response->disablePayload();
        }

        $match = $this->match($request);

        if (self::REQUEST_METHOD_OPTIONS === $method) {
            $groups = $match?->route->getGroups() ?? [];

            try {
                foreach ($groups as $group) {
                    foreach (self::$options as $option) { // Group options hooks
                        /** @var Hook $option */
                        if (\in_array($group, $option->getGroups())) {
                            \call_user_func_array($option->getAction(), $this->getArguments($option, [], $request->getParams(), $match->route));
                        }
                    }
                }

                foreach (self::$options as $option) { // Global options hooks
                    /** @var Hook $option */
                    if (\in_array('*', $option->getGroups())) {
                        \call_user_func_array($option->getAction(), $this->getArguments($option, [], $request->getParams(), $match?->route));
                    }
                }
            } catch (\Throwable $e) {
                foreach (self::$errors as $error) { // Global error hooks
                    /** @var Hook $error */
                    if (\in_array('*', $error->getGroups())) {
                        $this->context()->set('error', fn() => $e, []);
                        \call_user_func_array($error->getAction(), $this->getArguments($error, [], $request->getParams(), $match?->route));
                    }
                }
            }

            return $this;
        }

        if ($match === null) {
            foreach (self::$errors as $error) {
                if (\in_array('*', $error->getGroups())) {
                    $this->context()->set('error', fn() => new Exception('Not Found', 404), []);
                    \call_user_func_array($error->getAction(), $this->getArguments($error, [], $request->getParams()));
                }
            }

            return $this;
        }

        $route = $match->route;
        $arguments = [];
        $groups = $route->getGroups();

        try {
            if ($route->getHook()) {
                foreach (self::$init as $hook) { // Global init hooks
                    if (\in_array('*', $hook->getGroups())) {
                        $arguments = $this->getArguments($hook, $match->params, $request->getParams(), $route);
                        \call_user_func_array($hook->getAction(), $arguments);
                    }
                }
            }

            foreach ($groups as $group) {
                foreach (self::$init as $hook) { // Group init hooks
                    if (\in_array($group, $hook->getGroups())) {
                        $arguments = $this->getArguments($hook, $match->params, $request->getParams(), $route);
                        \call_user_func_array($hook->getAction(), $arguments);
                    }
                }
            }

            if (!$response->isSent()) {
                $arguments = $this->getArguments($route, $match->params, $request->getParams(), $route);
                \call_user_func_array($route->getAction(), $arguments);
            }

            foreach ($groups as $group) {
                foreach (self::$shutdown as $hook) { // Group shutdown hooks
                    if (\in_array($group, $hook->getGroups())) {
                        $arguments = $this->getArguments($hook, $match->params, $request->getParams(), $route);
                        \call_user_func_array($hook->getAction(), $arguments);
                    }
                }
            }

            if ($route->getHook()) {
                foreach (self::$shutdown as $hook) { // Group shutdown hooks
                    if (\in_array('*', $hook->getGroups())) {
                        $arguments = $this->getArguments($hook, $match->params, $request->getParams(), $route);
                        \call_user_func_array($hook->getAction(), $arguments);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->context()->set('error', fn() => $e, []);

            foreach ($groups as $group) {
                foreach (self::$errors as $error) { // Group error hooks
                    if (\in_array($group, $error->getGroups())) {
                        try {
                            $arguments = $this->getArguments($error, $match->params, $request->getParams(), $route);
                            \call_user_func_array($error->getAction(), $arguments);
                        } catch (\Throwable $hookError) {
                            throw new Exception('Error handler had an error: ' . $hookError::class . ': ' . $hookError->getMessage(), 500, $e);
                        }
                    }
                }
            }

            foreach (self::$errors as $error) { // Global error hooks
                if (\in_array('*', $error->getGroups())) {
                    try {
                        $arguments = $this->getArguments($error, $match->params, $request->getParams(), $route);
                        \call_user_func_array($error->getAction(), $arguments);
                    } catch (\Throwable $hookError) {
                        throw new Exception('Error handler had an error: ' . $hookError::class . ': ' . $hookError->getMessage(), 500, $e);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Get Arguments
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $requestParams
     * @return array<int, mixed>
     * @throws Exception
     */
    protected function getArguments(Hook $hook, array $values, array $requestParams, ?Route $route = null): array
    {
        $arguments = [];
        foreach ($hook->getParams() as $key => $param) { // Get value from route or request object
            $requestKey = $key;
            if (!\array_key_exists($key, $requestParams) && !empty($param['aliases'])) {
                foreach ($param['aliases'] as $alias) {
                    if (\array_key_exists($alias, $requestParams)) {
                        $requestKey = $alias;
                        break;
                    }
                }
            }

            $valuesKey = $key;
            if (!\array_key_exists($key, $values) && !empty($param['aliases'])) {
                foreach ($param['aliases'] as $alias) {
                    if (\array_key_exists($alias, $values)) {
                        $valuesKey = $alias;
                        break;
                    }
                }
            }

            $existsInRequest = \array_key_exists($requestKey, $requestParams);
            $existsInValues = \array_key_exists($valuesKey, $values);

            // An explicit null is treated as omitted unless the validator accepts null as a value
            if ($existsInRequest && !$existsInValues && $requestParams[$requestKey] === null && $param['optional'] && $param['default'] !== null && !$param['skipValidation']) {
                $validator = $this->resolveValidator($param);
                if ($validator instanceof Validator && !$validator->isValid(null)) {
                    $existsInRequest = false;
                }
            }

            $paramExists = $existsInRequest || $existsInValues;

            $arg = $existsInRequest ? $requestParams[$requestKey] : $param['default'];
            if (\is_callable($arg) && !\is_string($arg)) {
                $context = $this->adapter->context();
                $arg = \call_user_func_array($arg, array_map($context->get(...), $param['injections']));
            }
            $value = $existsInValues ? $values[$valuesKey] : $arg;

            if (!$param['skipValidation']) {
                if (!$paramExists && !$param['optional']) {
                    throw new Exception('Param "' . $key . '" is not optional.', 400);
                }

                if ($paramExists) {
                    $this->validate($key, $param, $value);
                }
            }

            $hook->setParamValue($key, $value);
            $arguments[$param['order']] = $value;
        }

        // Locals come from the dispatch frame, not the per-request context.
        // Writing them to context would leak across nested execute() calls
        // (e.g. sub-request dispatch).
        $locals = [
            'route' => $route,
            'params' => $values,
        ];

        foreach ($hook->getInjections() as $injection) {
            $arguments[$injection['order']] = \array_key_exists($injection['name'], $locals)
                ? $locals[$injection['name']]
                : $this->adapter->context()->get($injection['name']);
        }

        return $arguments;
    }

    /**
     * Handle a top-level HTTP request.
     *
     * This is the entry point wired into the server adapter for each
     * incoming request. It runs the full request lifecycle: compression
     * setup, request hooks, static-file serving, route match, dispatch,
     * and telemetry.
     *
     * For dispatching a sub-request from inside a handler (e.g. a
     * GraphQL resolver invoking another API route with a synthesized
     * Request/Response), use {@see execute()} instead — it skips the
     * outer-request setup that has already run.
     */
    public function run(Request $request, Response $response): static
    {
        $this->activeRequests->add(1, [
            'http.request.method' => $request->getMethod(),
            'url.scheme' => $request->getProtocol(),
        ]);

        $start = microtime(true);
        $result = $this->runInternal($request, $response);

        $requestDuration = microtime(true) - $start;
        $attributes = [
            'url.scheme' => $request->getProtocol(),
            'http.request.method' => $request->getMethod(),
            // OTel semantics: http.route is the matched route template, or
            // unset when no template applies (wildcard / no match).
            'http.route' => ($this->match($request)?->route->getPath() ?: null),
            'http.response.status_code' => $response->getStatusCode(),
        ];
        $this->requestDuration->record($requestDuration, $attributes);
        $this->requestBodySize->record($request->getSize(), $attributes);
        $this->responseBodySize->record($response->getSize(), $attributes);
        $this->activeRequests->add(-1, [
            'http.request.method' => $request->getMethod(),
            'url.scheme' => $request->getProtocol(),
        ]);

        return $result;
    }

    /**
     * Run internal
     *
     * This is the place to initialize any pre routing logic.
     * This is where you might want to parse the application current URL by any desired logic
     *
     * @param Response $response;
     */
    private function runInternal(Request $request, Response $response): static
    {
        if ($this->compression) {
            $response->setAcceptEncoding($request->getHeaderLine('accept-encoding', ''));
            $response->setCompressionMinSize($this->compressionMinSize);
            $response->setCompressionSupported($this->compressionSupported);
        }

        $this->context()->set('request', fn() => $request);
        $this->context()->set('response', fn() => $response);

        try {
            foreach (self::$requestHooks as $hook) {
                $arguments = $this->getArguments($hook, [], []);
                \call_user_func_array($hook->getAction(), $arguments);
            }
        } catch (\Exception $e) {
            $this->context()->set('error', fn() => $e, []);

            foreach (self::$errors as $error) { // Global error hooks
                if (\in_array('*', $error->getGroups())) {
                    try {
                        $arguments = $this->getArguments($error, [], []);
                        \call_user_func_array($error->getAction(), $arguments);
                    } catch (\Throwable $hookError) {
                        throw new Exception('Error handler had an error: ' . $hookError::class . ': ' . $hookError->getMessage(), 500, $e);
                    }
                }
            }
        }

        if ($this->isFileLoaded($request->getURI())) {
            $time = (60 * 60 * 24 * 365 * 2); // 45 days cache

            $response
                ->setContentType($this->getFileMimeType($request->getURI()))
                ->addHeader('Cache-Control', 'public, max-age=' . $time)
                ->addHeader('Expires', date('D, d M Y H:i:s', time() + $time) . ' GMT') // 45 days cache
                ->send($this->getFileContents($request->getURI()));

            return $this;
        }

        return $this->execute($request, $response);
    }


    /**
     * Validate Param
     *
     * Creates an validator instance and validate given value with given rules.
     *
     * @param  array<string, mixed>  $param
     *
     * @throws Exception
     */
    protected function validate(string $key, array $param, mixed $value): void
    {
        if ($param['optional'] && \is_null($value)) {
            return;
        }

        $validator = $this->resolveValidator($param);

        if (!$validator instanceof Validator) { // is the validator object an instance of the Validator class
            throw new Exception('Validator object is not an instance of the Validator class', 500);
        }

        if (!$validator->isValid($value)) {
            throw new Exception('Invalid `' . $key . '` param: ' . $validator->getDescription(), 400);
        }
    }

    /**
     * Build the param's validator, resolving a factory closure with its injections.
     *
     * @param  array<string, mixed>  $param
     */
    private function resolveValidator(array $param): mixed
    {
        $validator = $param['validator'];

        if (\is_callable($validator)) {
            $context = $this->adapter->context();
            $validator = \call_user_func_array($validator, array_map($context->get(...), $param['injections']));
        }

        return $validator;
    }

    /**
     * Reset all the static variables
     */
    public static function reset(): void
    {
        Router::reset();
        self::$mode = '';
        self::$errors = [];
        self::$init = [];
        self::$shutdown = [];
        self::$options = [];
        self::$startHooks = [];
        self::$requestHooks = [];
    }
}
