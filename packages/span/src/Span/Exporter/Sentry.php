<?php

namespace Utopia\Span\Exporter;

use Closure;
use Composer\InstalledVersions;
use Psr\Http\Client\ClientInterface;
use Utopia\Client\Adapter\Curl\Client as CurlClient;
use Utopia\Client as HttpClient;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Span\Exporter\Sentry\Level as SentryLevel;
use Utopia\Span\Level;
use Utopia\Span\Span;

/**
 * Exports warning-or-higher spans to Sentry Issues.
 *
 * Only spans whose level is warning, error, or fatal are sent, at that level.
 * Lower levels (info, debug) are skipped — use the Stdout exporter for those.
 *
 * ## Exceptions
 *
 * The span's error and its full getPrevious() chain (up to 10 links) are sent
 * as chained exception values, so Sentry shows the root cause alongside the
 * reported exception. Each value carries a mechanism whose handled flag comes
 * from a boolean `span.handled` attribute when set; otherwise warning/error
 * spans are marked handled and fatal spans unhandled.
 *
 * ## HTTP Attribute Conventions
 *
 * The following span attributes are mapped to Sentry's request/response structures:
 *
 * Request attributes:
 * - `http.url` - Full request URL
 * - `http.method` - HTTP method (GET, POST, etc.)
 * - `http.query` - Query string
 *
 * Response attributes:
 * - `http.response.status_code` - HTTP response status code
 *
 * ## Attribute Classification
 *
 * By default, all unhandled attributes go to `context` (recommended by Sentry).
 * Use the `$classifier` callback to control where attributes are placed:
 *
 * ```php
 * $exporter = new Sentry($dsn, classifier: function(string $key): SentryField {
 *     return match(true) {
 *         str_starts_with($key, 'tenant.') => SentryField::Tag,
 *         default => SentryField::Context,
 *     };
 * });
 * ```
 */
class Sentry implements Exporter
{
    private static ?string $sdkVersion = null;

    /**
     * Span levels that are exported. Anything below warning (info, debug) is skipped.
     */
    private const array EXPORT_LEVELS = [Level::Warn, Level::Error, Level::Fatal];

    /**
     * Maximum number of chained exceptions (via getPrevious()) sent per event.
     */
    private const int MAX_CHAIN_DEPTH = 10;

    private readonly string $endpoint;
    private readonly string $publicKey;
    private readonly string $projectId;

    /** @var Closure(string): SentryField */
    private readonly Closure $classifier;

    /** @var Closure(Span): bool */
    private readonly Closure $sampler;

    private readonly ClientInterface $client;

    private readonly RequestFactory $requestFactory;

    /**
     * Create a new Sentry exporter.
     *
     * Sentry only exports spans at warning level or above; a custom sampler is composed (AND)
     * with the built-in level filter, so it can further restrict — but not broaden — what is sent.
     *
     * @param Closure(Span): bool|null $sampler Optional additional filter, composed with the level filter.
     * @param string $dsn Sentry DSN (e.g., https://key@sentry.io/123)
     * @param string|null $environment Optional environment name (e.g., 'production')
     * @param string|null $release Optional release/version identifier (e.g., commit hash)
     * @param string|null $serverName Optional server name/identifier
     * @param Closure(string): SentryField|null $classifier Optional callback to classify attributes
     * @param ClientInterface|null $client Optional PSR-18 transport; defaults to `utopia-php/client` with its cURL adapter
     */
    public function __construct(
        ?Closure $sampler = null,
        private readonly string $dsn = '',
        private readonly ?string $environment = null,
        private readonly ?string $release = null,
        private readonly ?string $serverName = null,
        ?Closure $classifier = null,
        ?ClientInterface $client = null,
    ) {
        $this->classifier = $classifier ?? static fn(string $key): SentryField => SentryField::Context;
        $this->client = $client ?? new HttpClient(
            new CurlClient(options: [
                \CURLOPT_TIMEOUT_MS => 1000,
                \CURLOPT_CONNECTTIMEOUT_MS => 500,
            ]),
        );
        $this->requestFactory = new RequestFactory();
        $this->sampler = static function (Span $span) use ($sampler): bool {
            $level = Level::tryFrom((string) $span->get('level'));
            if ($level === null || !\in_array($level, self::EXPORT_LEVELS, true)) {
                return false;
            }
            return !$sampler instanceof \Closure || $sampler($span);
        };
        if ($dsn === '') {
            throw new \InvalidArgumentException('Sentry DSN is required');
        }

        $parsed = parse_url($dsn);

        if ($parsed === false) {
            throw new \InvalidArgumentException('Invalid Sentry DSN');
        }

        $publicKey = $parsed['user'] ?? '';
        $host = $parsed['host'] ?? '';
        $projectId = ltrim($parsed['path'] ?? '', '/');

        if ($publicKey === '' || $host === '' || $projectId === '') {
            throw new \InvalidArgumentException('Invalid Sentry DSN: must include public key, host, and project ID');
        }

        $this->publicKey = $publicKey;
        $this->projectId = $projectId;

        $scheme = $parsed['scheme'] ?? 'https';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        $this->endpoint = "{$scheme}://{$host}{$port}/api/{$this->projectId}/envelope/";
    }

    public function sample(Span $span): bool
    {
        return ($this->sampler)($span);
    }

    public function export(Span $span): void
    {
        $envelope = $this->buildEnvelope($span);

        if ($envelope === null) {
            return;
        }

        $this->sendWithClient($this->client, $envelope);
    }

    private function sendWithClient(ClientInterface $client, string $envelope): void
    {
        $request = $this->requestFactory->body(
            Method::POST,
            $this->endpoint,
            $envelope,
            'application/x-sentry-envelope',
            ['X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$this->publicKey}"],
        );

        try {
            $response = $client->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                error_log("Sentry exporter: HTTP {$statusCode} - {$response->getBody()}");
            }
        } catch (\Throwable $error) {
            error_log('Sentry exporter: ' . $error->getMessage());
        }
    }

    private function buildEnvelope(Span $span): ?string
    {
        $error = $span->getError();

        if (!$error instanceof \Throwable) {
            return null;
        }

        $attributes = $span->getAttributes();

        $traceId = (string) ($attributes['span.trace_id'] ?? '');
        $spanId = (string) ($attributes['span.id'] ?? '');
        $parentId = $attributes['span.parent_id'] ?? null;
        $startedAt = (float) ($attributes['span.started_at'] ?? microtime(true));
        $finishedAt = (float) ($attributes['span.finished_at'] ?? microtime(true));
        $action = $span->getAction();

        $traceContext = [
            'trace_id' => $traceId,
            'span_id' => $spanId,
        ];

        if (\is_string($parentId)) {
            $traceContext['parent_span_id'] = $parentId;
        }

        $header = json_encode([
            'event_id' => str_replace('-', '', $traceId),
            'sent_at' => date('c'),
            'dsn' => $this->dsn,
        ]);

        $itemHeader = json_encode([
            'type' => 'event',
            'content_type' => 'application/json',
        ]);

        $contexts = [
            'trace' => $traceContext,
            'runtime' => [
                'name' => 'php',
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
        ];

        $request = $this->buildRequest($attributes);
        $response = $this->buildResponse($attributes);

        if ($response !== []) {
            $contexts['response'] = $response;
        }

        [$tags, $customContexts, $extra] = $this->classifyAttributes($attributes);

        if ($customContexts !== []) {
            $contexts['custom'] = $customContexts;
        }

        $level = Level::tryFrom((string) $attributes['level']) ?? Level::Error;

        $payloadData = [
            'level' => SentryLevel::fromSpan($level)->value,
            'platform' => 'php',
            'sdk' => [
                'name' => 'utopia-php/span',
                'version' => self::$sdkVersion ??= InstalledVersions::getVersion('utopia-php/span') ?? 'unknown',
            ],
            'start_timestamp' => $startedAt,
            'timestamp' => $finishedAt,
            'transaction' => $action,
            'message' => $error->getMessage(),
            'contexts' => $contexts,
            'exception' => [
                'values' => $this->buildExceptionValues($error, $level, \is_bool($attributes['span.handled'] ?? null) ? $attributes['span.handled'] : null),
            ],
        ];

        if ($tags !== []) {
            $payloadData['tags'] = $tags;
        }

        if ($extra !== []) {
            $payloadData['extra'] = $extra;
        }

        if ($request !== []) {
            $payloadData['request'] = $request;
        }

        if ($this->environment !== null) {
            $payloadData['environment'] = $this->environment;
        }

        if ($this->release !== null) {
            $payloadData['release'] = $this->release;
        }

        if ($this->serverName !== null) {
            $payloadData['server_name'] = $this->serverName;
        }

        $payload = json_encode($payloadData);

        if ($header === false || $itemHeader === false || $payload === false) {
            return null;
        }

        return "{$header}\n{$itemHeader}\n{$payload}";
    }

    /**
     * Build the Sentry exception values list, following the getPrevious() chain.
     *
     * Values are ordered oldest cause first (root cause at index 0, reported
     * exception last), as required by the Sentry exception interface. Each value
     * carries a mechanism; chained values are linked via exception_id/parent_id
     * so Sentry renders the cause tree.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildExceptionValues(\Throwable $error, Level $level, ?bool $handledOverride = null): array
    {
        $chain = [];
        for ($current = $error; $current instanceof \Throwable && \count($chain) < self::MAX_CHAIN_DEPTH; $current = $current->getPrevious()) {
            $chain[] = $current;
        }

        // A 'span.handled' attribute states the handling state explicitly; the
        // level is only a fallback heuristic (fatal implies a process-level
        // handler, anything reported at warning/error implies user code).
        $handled = $handledOverride ?? ($level !== Level::Fatal);
        $chained = \count($chain) > 1;
        $values = [];

        // $chain[0] is the reported (outermost) exception — the root of the
        // mechanism tree, so it gets exception_id 0.
        foreach ($chain as $id => $exception) {
            $mechanism = [
                'type' => 'generic',
                'handled' => $handled,
            ];

            if ($chained) {
                $mechanism['exception_id'] = $id;
                if ($id > 0) {
                    $mechanism['parent_id'] = $id - 1;
                    $mechanism['source'] = '__previous__';
                }
            }

            $value = [
                'type' => $exception::class,
                'value' => $exception->getMessage(),
                'stacktrace' => ['frames' => $this->buildFrames($exception)],
                'mechanism' => $mechanism,
            ];

            $namespaceEnd = strrpos($exception::class, '\\');
            if ($namespaceEnd !== false) {
                $value['module'] = substr($exception::class, 0, $namespaceEnd);
            }

            $values[] = $value;
        }

        return array_reverse($values);
    }

    /**
     * Build Sentry stacktrace frames for one throwable, oldest call first,
     * ending with the throw site.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFrames(\Throwable $error): array
    {
        $trace = $error->getTrace();

        $frames = [];
        foreach (array_reverse($trace) as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }
            $frames[] = [
                'filename' => $frame['file'],
                'lineno' => $frame['line'] ?? 0,
                'in_app' => !str_contains($frame['file'], '/vendor/'),
                'function' => $this->formatFunction($frame),
            ];
        }

        // PHP traces describe call sites, not the throw site itself — append it.
        // Its enclosing function is the callee recorded in the first trace frame.
        $throwSite = [
            'filename' => $error->getFile(),
            'lineno' => $error->getLine(),
            'in_app' => !str_contains($error->getFile(), '/vendor/'),
        ];

        if (isset($trace[0]['function'])) {
            $throwSite['function'] = $this->formatFunction($trace[0]);
        }

        $frames[] = $throwSite;

        return $frames;
    }

    /**
     * @param array{function: string, class?: class-string, type?: string} $frame
     */
    private function formatFunction(array $frame): string
    {
        return isset($frame['class'])
            ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function']
            : $frame['function'];
    }

    private const array HANDLED_HTTP_KEYS = [
        'http.url',
        'http.method',
        'http.query',
        'http.response.status_code',
    ];

    /**
     * Classify attributes into tags, contexts, and extra based on the classifier.
     *
     * @param array<string, mixed> $attributes
     * @return array{array<string, string>, array<string, mixed>, array<string, mixed>}
     */
    private function classifyAttributes(array $attributes): array
    {
        $tags = [];
        $contexts = [];
        $extra = [];

        foreach ($attributes as $key => $value) {
            // Skip internal span attributes and level (handled in payload)
            if (str_starts_with((string) $key, 'span.')) {
                continue;
            }
            if ($key === 'level') {
                continue;
            }
            // Skip only the HTTP attributes we handle in buildRequest/buildResponse
            if (\in_array($key, self::HANDLED_HTTP_KEYS, true)) {
                continue;
            }

            $field = ($this->classifier)($key);

            switch ($field) {
                case SentryField::Tag:
                    // Tags must be strings, max 200 chars
                    if (\is_scalar($value) || $value === null) {
                        $tags[$key] = substr((string) $value, 0, 200);
                    }
                    break;
                case SentryField::Context:
                    $contexts[$key] = $value;
                    break;
                case SentryField::Extra:
                    $extra[$key] = $value;
                    break;
            }
        }

        return [$tags, $contexts, $extra];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function buildRequest(array $attributes): array
    {
        $request = [];

        if (isset($attributes['http.url']) && \is_string($attributes['http.url'])) {
            $request['url'] = $attributes['http.url'];
        }

        if (isset($attributes['http.method']) && \is_string($attributes['http.method'])) {
            $request['method'] = $attributes['http.method'];
        }

        if (isset($attributes['http.query']) && \is_string($attributes['http.query'])) {
            $request['query_string'] = $attributes['http.query'];
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function buildResponse(array $attributes): array
    {
        $response = [];

        if (isset($attributes['http.response.status_code']) && \is_int($attributes['http.response.status_code'])) {
            $response['status_code'] = $attributes['http.response.status_code'];
        }

        return $response;
    }
}
