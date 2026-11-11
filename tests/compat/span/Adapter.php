<?php

namespace Tests\Compat\Span;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Codec;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Psr7\Response\Factory as ResponseFactory;
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Exporter\None;
use Utopia\Span\Exporter\Pretty;
use Utopia\Span\Exporter\Sentry;
use Utopia\Span\Exporter\Sentry\Level as SentryLevel;
use Utopia\Span\Exporter\SentryField;
use Utopia\Span\Exporter\Stdout;
use Utopia\Span\Level;
use Utopia\Span\Span;
use Utopia\Span\Storage\Auto;
use Utopia\Span\Storage\Coroutine;
use Utopia\Span\Storage\Memory;
use Utopia\Span\Storage\Storage;

/** A namespaced exception class for Sentry's `module`. */
class NamespacedException extends \RuntimeException
{
}

/** Records the spans it exports (SpanTest::createExporter()). */
final class Recorder implements Exporter
{
    /** @var list<Span> */
    public array $exported = [];

    public function __construct(private readonly \Closure $sampler)
    {
    }

    public function sample(Span $span): bool
    {
        return ($this->sampler)($span);
    }

    public function export(Span $span): void
    {
        $this->exported[] = $span;
    }
}

/** Captures the request a Sentry exporter sends. */
final class Capture implements ClientInterface
{
    public ?RequestInterface $request = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return new ResponseFactory()->createResponse(202);
    }
}

/**
 * Maps tests/compat/span/spec.json operations onto utopia-php/span. Glue only: no logic.
 *
 * Stdout and Pretty write to the STDOUT and STDERR streams, which carry the
 * driver's protocol, so their exports run in a child process (export.php)
 * whose streams are captured.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        return [
            'add' => ['Set an attribute on the current span', function (Session $s) {
                Span::setExporters();
                Span::setStorage(new Memory());
                Span::init('http.request');

                return fn () => Span::add('http.method', 'GET');
            }],
            'set' => ['Set an attribute on a span held by the caller', function (Session $s) {
                $span = new Span('http.request');

                return fn () => $span->set('http.response.status_code', 200);
            }],
            'lifecycle' => ['Start a span, add three attributes and finish it without exporters', function (Session $s) {
                Span::setExporters();
                Span::setStorage(new Memory());

                return function () {
                    $span = Span::init('http.request');
                    Span::add('http.method', 'GET');
                    Span::add('http.path', '/v1/users');
                    Span::add('http.response.status_code', 200);
                    $span->finish();
                };
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'span.reset' => function (array $a, Session $s) {
                Span::setExporters();
                Span::setStorage(new Memory());

                return;
            },
            'span.set_storage' => function (array $a, Session $s) {
                Span::setStorage(isset($a['kind']) ? self::storage($a['kind']) : null);

                return;
            },
            'span.set_exporters' => function (array $a, Session $s) {
                Span::setExporters(...array_map(fn ($h) => self::recorder($h, $s), $a['exporters'] ?? []));

                return;
            },
            'span.recorder' => fn (array $a, Session $s) => $s->handle(new Recorder(self::sampler($a['sampler'] ?? ['kind' => 'all']))),
            'span.recorded' => fn (array $a, Session $s) => array_map(fn (Span $span) => [
                'action' => $span->getAction(),
                'name' => $span->get('name'),
                'error' => $span->getError()?->getMessage(),
            ], self::recorder($a['recorder'], $s)->exported),
            'span.recorded_is' => fn (array $a, Session $s) => (self::recorder($a['recorder'], $s)->exported[$a['index']] ?? null) === self::span($a['span'], $s),
            'span.init' => fn (array $a, Session $s) => $s->handle(Span::init($a['action'], $a['traceparent'] ?? null)),
            'span.current' => fn (array $a, Session $s) => Span::current() === null ? null : (isset($a['span']) ? Span::current() === self::span($a['span'], $s) : true),
            'span.add' => function (array $a, Session $s) {
                Span::add($a['key'], $a['value']);

                return;
            },
            'span.current_traceparent' => fn (array $a, Session $s) => Span::traceparent(),
            'span.new' => fn (array $a, Session $s) => $s->handle(isset($a['action']) ? new Span($a['action']) : new Span()),
            'span.set' => fn (array $a, Session $s) => self::span($a['span'], $s)->set($a['key'], $a['value']) === self::span($a['span'], $s),
            'span.get' => fn (array $a, Session $s) => self::span($a['span'], $s)->get($a['key']),
            'span.describe' => function (array $a, Session $s) {
                $value = self::span($a['span'], $s)->get($a['key']);

                return \is_string($value)
                    ? ['type' => 'string', 'length' => \strlen($value), 'hex' => ctype_xdigit($value)]
                    : ['type' => get_debug_type($value)];
            },
            'span.attributes' => fn (array $a, Session $s) => self::pairs(self::span($a['span'], $s)->getAttributes()),
            'span.set_error' => fn (array $a, Session $s) => self::span($a['span'], $s)->setError(self::failure($a['error'])) === self::span($a['span'], $s),
            'span.error' => fn (array $a, Session $s) => self::describe(self::span($a['span'], $s)->getError()),
            'span.action' => fn (array $a, Session $s) => self::span($a['span'], $s)->getAction(),
            'span.traceparent' => fn (array $a, Session $s) => self::span($a['span'], $s)->getTraceparent(),
            'span.finish' => function (array $a, Session $s) {
                self::span($a['span'], $s)->finish(
                    isset($a['level']) ? Level::from($a['level']) : null,
                    isset($a['error']) ? self::failure($a['error']) : null,
                );

                return;
            },
            'span.sleep' => function (array $a, Session $s) {
                usleep($a['us']);

                return;
            },
            'span.duration_in' => function (array $a, Session $s) {
                $duration = self::span($a['span'], $s)->get('span.duration');

                return \is_float($duration) && $duration > $a['min'] && $duration < $a['max'];
            },
            'span.script' => function (array $a, Session $s) {
                $span = new Span($a['action'] ?? 'unknown');
                $results = [];
                foreach ($a['calls'] as $call) {
                    $results[] = match ($call['op']) {
                        'set' => $span->set($call['key'], $call['value']) === $span,
                        'get' => $span->get($call['key']),
                        default => throw new Fault("unknown call {$call['op']}"),
                    };
                }
                $attributes = $span->getAttributes();
                unset($attributes['span.trace_id'], $attributes['span.id'], $attributes['span.started_at']);

                return ['results' => $results, 'attributes' => self::pairs($attributes)];
            },
            'level.parse' => fn (array $a, Session $s) => Level::tryFrom($a['name'])?->value,
            'sentry.level' => fn (array $a, Session $s) => SentryLevel::fromSpan(Level::from($a['level']))->value,
            'storage.new' => fn (array $a, Session $s) => $s->handle(self::storage($a['kind'])),
            'storage.set' => function (array $a, Session $s) {
                self::storageOf($a['storage'], $s)->set(isset($a['span']) ? self::span($a['span'], $s) : null);

                return;
            },
            'storage.get' => function (array $a, Session $s) {
                $current = self::storageOf($a['storage'], $s)->get();

                return $current === null ? null : (isset($a['span']) ? $current === self::span($a['span'], $s) : true);
            },
            'storage.coroutine' => function (array $a, Session $s) {
                $storage = self::storage($a['kind']);
                $span = new Span('task');
                $inside = null;
                \Swoole\Coroutine\run(function () use ($storage, $span, &$inside) {
                    $storage->set($span);
                    $inside = $storage->get() === $span;
                });

                return ['inside' => $inside, 'outside' => $storage->get() === null ? null : $storage->get() === $span];
            },
            'exporter.sample' => fn (array $a, Session $s) => self::exporter($a['exporter'], (array) ($a['options'] ?? []))->sample(self::build($a['span'])),
            'exporter.export' => fn (array $a, Session $s) => match ($a['exporter']) {
                'none' => (function () use ($a) {
                    (new None())->export(self::build($a['span']));

                    return ['stdout' => '', 'stderr' => ''];
                })(),
                default => self::child($a),
            },
            'sentry.new' => function (array $a, Session $s) {
                new Sentry(dsn: $a['dsn'], client: new Capture());

                return true;
            },
            'sentry.export' => function (array $a, Session $s) {
                $client = new Capture();
                $exporter = self::sentry($a, $client);
                $exporter->export(self::build($a['span']));
                $request = $client->request;
                if ($request === null) {
                    return;
                }

                return [
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                    'content_type' => $request->getHeaderLine('Content-Type'),
                    'auth' => $request->getHeaderLine('X-Sentry-Auth'),
                    'envelope' => array_map(fn (string $line) => json_decode($line, true), explode("\n", (string) $request->getBody())),
                ];
            },
        ];
    }

    /**
     * Runs an export in export.php and returns what it wrote.
     *
     * @return array{stdout: string, stderr: string}
     */
    private static function child(array $a): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/export.php'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!\is_resource($process)) {
            throw new Fault('cannot start export.php');
        }
        fwrite($pipes[0], json_encode(Codec::encode($a), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    /** export.php: exports the described span with the described exporter. */
    public static function exportHere(array $a): void
    {
        self::exporter($a['exporter'], (array) ($a['options'] ?? []))->export(self::build($a['span']));
    }

    private static function exporter(string $kind, array $options): Exporter
    {
        $sampler = isset($options['sampler']) ? self::sampler($options['sampler']) : null;

        return match ($kind) {
            'none' => new None(),
            'stdout' => new Stdout($sampler, ...(isset($options['max_trace_frames']) ? ['maxTraceFrames' => $options['max_trace_frames']] : [])),
            'pretty' => new Pretty(
                $sampler,
                ...array_filter(['maxTraceFrames' => $options['max_trace_frames'] ?? null, 'width' => $options['width'] ?? null], fn ($v) => $v !== null),
            ),
            'sentry' => self::sentry($options, new Capture()),
            default => throw new Fault("unknown exporter {$kind}"),
        };
    }

    private static function sentry(array $o, ClientInterface $client): Sentry
    {
        $classifier = null;
        if (isset($o['classifier'])) {
            $fields = ['tag' => SentryField::Tag, 'context' => SentryField::Context, 'extra' => SentryField::Extra];
            $rules = $o['classifier'];
            $classifier = function (string $key) use ($rules, $fields): SentryField {
                foreach ($rules['prefixes'] ?? [] as [$prefix, $field]) {
                    if (str_starts_with($key, $prefix)) {
                        return $fields[$field] ?? throw new Fault('unknown field');
                    }
                }

                return $fields[$rules['default'] ?? 'context'] ?? throw new Fault('unknown field');
            };
        }

        return new Sentry(
            sampler: isset($o['sampler']) ? self::sampler($o['sampler']) : null,
            dsn: $o['dsn'],
            environment: $o['environment'] ?? null,
            release: $o['release'] ?? null,
            serverName: $o['server_name'] ?? null,
            classifier: $classifier,
            client: $client,
        );
    }

    /**
     * A span from its description: {action?, base?: {trace_id, span_id, started_at}, attributes: [{key, value}...], error?}.
     */
    private static function build(array $d): Span
    {
        $span = isset($d['action']) ? new Span($d['action']) : new Span();
        foreach (['trace_id' => 'span.trace_id', 'span_id' => 'span.id', 'started_at' => 'span.started_at', 'finished_at' => 'span.finished_at'] as $field => $key) {
            if (isset($d['base'][$field])) {
                $span->set($key, $d['base'][$field]);
            }
        }
        foreach ($d['attributes'] ?? [] as $attribute) {
            $span->set($attribute['key'], $attribute['value']);
        }
        if (isset($d['error'])) {
            $span->setError(self::failure($d['error']));
        }

        return $span;
    }

    /**
     * A throwable from its description: {class, message, code?, file?, line?, trace?, previous?}.
     */
    private static function failure(array $f): \Throwable
    {
        $previous = isset($f['previous']) ? self::failure($f['previous']) : null;
        $class = $f['class'];
        $error = new $class($f['message'] ?? '', $f['code'] ?? 0, $previous);
        $base = $error instanceof \Exception ? \Exception::class : \Error::class;
        (new \ReflectionProperty($base, 'file'))->setValue($error, $f['file'] ?? 'unknown.php');
        (new \ReflectionProperty($base, 'line'))->setValue($error, $f['line'] ?? 0);
        (new \ReflectionProperty($base, 'trace'))->setValue($error, array_map(
            fn (array $frame) => array_filter([
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'],
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ], fn ($v) => $v !== null),
            $f['trace'] ?? [],
        ));

        return $error;
    }

    private static function describe(?\Throwable $error): ?array
    {
        return $error === null ? null : [
            'class' => $error::class,
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'previous' => self::describe($error->getPrevious()),
        ];
    }

    private static function sampler(array $sampler): \Closure
    {
        return match ($sampler['kind']) {
            'all' => fn (Span $span) => true,
            'none' => fn (Span $span) => false,
            'error' => fn (Span $span) => $span->getError() instanceof \Throwable,
            'action' => fn (Span $span) => $span->getAction() === $sampler['action'],
            'duration_over' => fn (Span $span) => $span->get('span.duration') > $sampler['seconds'],
            default => throw new Fault("unknown sampler {$sampler['kind']}"),
        };
    }

    private static function storage(string $kind): Storage
    {
        return match ($kind) {
            'memory' => new Memory(),
            'auto' => new Auto(),
            'coroutine' => new Coroutine(),
            default => throw new Fault("unknown storage {$kind}"),
        };
    }

    /**
     * @param array<array-key, mixed> $attributes
     * @return list<array{0: string, 1: mixed}>
     */
    private static function pairs(array $attributes): array
    {
        $pairs = [];
        foreach ($attributes as $key => $value) {
            $pairs[] = [(string) $key, $value];
        }

        return $pairs;
    }

    private static function span(mixed $handle, Session $s): Span
    {
        $span = $s->get($handle);

        return $span instanceof Span ? $span : throw new Fault('not a Span');
    }

    private static function recorder(mixed $handle, Session $s): Recorder
    {
        $recorder = $s->get($handle);

        return $recorder instanceof Recorder ? $recorder : throw new Fault('not a recorder');
    }

    private static function storageOf(mixed $handle, Session $s): Storage
    {
        $storage = $s->get($handle);

        return $storage instanceof Storage ? $storage : throw new Fault('not a Storage');
    }
}
