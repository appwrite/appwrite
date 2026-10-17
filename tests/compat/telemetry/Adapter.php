<?php

namespace Tests\Compat\Telemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Metrics\V1\HistogramDataPoint;
use Opentelemetry\Proto\Metrics\V1\NumberDataPoint;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Server as TcpServer;
use Swoole\Coroutine\Server\Connection as TcpConnection;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None;
use Utopia\Telemetry\Adapter\OpenTelemetry;
use Utopia\Telemetry\Adapter\OpenTelemetry\Transport\Swoole;
use Utopia\Telemetry\Adapter\Test;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

/**
 * Maps tests/compat/telemetry/spec.json operations onto utopia-php/telemetry. Glue only.
 */
final class Adapter implements Base, Benchmarks
{
    /**
     * The callbacks a Test backend's observable gauge holds (an anonymous class).
     *
     * @return list<\Closure>
     */
    private static function callbacks(?object $gauge): array
    {
        $callbacks = $gauge === null ? [] : (get_object_vars($gauge)['callbacks'] ?? []);

        return \is_array($callbacks) ? array_values(array_filter($callbacks, static fn ($c) => $c instanceof \Closure)) : [];
    }

    public function benchmarks(): array
    {
        return [
            'counter_memory' => ['Add to a counter of the in-memory (Test) backend with one attribute', function (Session $s) {
                $counter = (new Test())->createCounter('bench.counter');

                return fn () => $counter->add(1, ['route' => '/v1/health']);
            }],
            'counter_none' => ['Add to a counter of the no-op backend', function (Session $s) {
                $counter = (new None())->createCounter('bench.counter');

                return fn () => $counter->add(1, ['route' => '/v1/health']);
            }],
            'counter_otlp' => ['Add to an OpenTelemetry counter with two attributes', function (Session $s) {
                $counter = self::otlp(new \ArrayObject())->createCounter('bench.counter');

                return fn () => $counter->add(1, ['route' => '/v1/health', 'method' => 'GET']);
            }],
            'histogram_otlp' => ['Record a duration on an OpenTelemetry histogram with one attribute', function (Session $s) {
                $histogram = self::otlp(new \ArrayObject())->createHistogram('bench.duration', 's');

                return fn () => $histogram->record(0.002, ['operation' => 'load']);
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'telemetry.memory' => fn (array $a, Session $s) => $s->handle(new Test()),
            'telemetry.none' => fn (array $a, Session $s) => $s->handle(new None()),
            'telemetry.otlp' => function (array $a, Session $s) {
                $payloads = new \ArrayObject();
                $telemetry = self::otlp($payloads, $a['namespace'] ?? 'namespace', $a['service'] ?? 'service', $a['instance'] ?? 'instance');
                self::$payloads[spl_object_id($telemetry)] = $payloads;

                return $s->handle($telemetry);
            },
            'telemetry.collect' => fn (array $a, Session $s) => self::telemetry($a, $s)->collect(),
            'instrument.new' => fn (array $a, Session $s) => $s->handle(self::instrument(self::telemetry($a, $s), $a)),
            'instrument.record' => function (array $a, Session $s) {
                $instrument = $s->get($a['instrument']);
                $attributes = self::attributes($a['attributes'] ?? []);
                $scope = ($a['sampled_span'] ?? false)
                    ? Span::wrap(SpanContext::create('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', TraceFlags::SAMPLED))
                        ->storeInContext(Context::getCurrent())
                        ->activate()
                    : null;
                try {
                    match (true) {
                        $instrument instanceof Counter, $instrument instanceof UpDownCounter => $instrument->add($a['value'], $attributes),
                        $instrument instanceof Histogram, $instrument instanceof Gauge => $instrument->record($a['value'], $attributes),
                        default => throw new Fault('not a synchronous instrument'),
                    };
                } finally {
                    $scope?->detach();
                }

                return;
            },
            'instrument.observe' => function (array $a, Session $s) {
                $instrument = $s->get($a['instrument']);
                $source = $s->get($a['source']);
                if (!$instrument instanceof ObservableGauge || !$source instanceof \ArrayObject) {
                    throw new Fault('not an observable gauge and a source');
                }
                $instrument->observe(function (callable $observe) use ($source): void {
                    foreach ($source as $observation) {
                        $observe($observation['value'], self::attributes($observation['attributes'] ?? []));
                    }
                });

                return;
            },
            'source.new' => fn (array $a, Session $s) => $s->handle(new \ArrayObject($a['observations'] ?? [])),
            'source.set' => function (array $a, Session $s) {
                $source = $s->get($a['source']);
                $source instanceof \ArrayObject ? $source->exchangeArray($a['observations']) : throw new Fault('not a source');

                return;
            },
            'memory.dump' => fn (array $a, Session $s) => self::dump(self::memory($a, $s)),
            'memory.observe' => function (array $a, Session $s) {
                $gauge = self::memory($a, $s)->observableGauges[$a['name']] ?? null;
                if ($gauge === null) {
                    return;
                }
                $observed = [];
                foreach (self::callbacks($gauge) as $callback) {
                    $callback(function (float|int $value, iterable $attributes = []) use (&$observed): void {
                        $pairs = [];
                        foreach ($attributes as $k => $v) {
                            $pairs[] = [$k, $v];
                        }
                        $observed[] = [$value, $pairs];
                    });
                }

                return $observed;
            },
            'otlp.payloads' => function (array $a, Session $s) {
                $telemetry = self::telemetry($a, $s);
                $payloads = self::$payloads[spl_object_id($telemetry)] ?? throw new Fault('not an OpenTelemetry backend');

                return array_map(self::decode(...), $payloads->getArrayCopy());
            },
            // Records every {kind, name, value, attributes} of `records` on a
            // fresh backend, collects twice, and reports what it holds.
            'telemetry.replay' => function (array $a, Session $s) {
                $payloads = new \ArrayObject();
                $telemetry = match ($a['backend']) {
                    'memory' => new Test(),
                    'none' => new None(),
                    default => self::otlp($payloads),
                };
                foreach ($a['records'] as $record) {
                    $instrument = self::instrument($telemetry, $record);
                    $attributes = self::attributes($record['attributes'] ?? []);
                    $instrument instanceof Counter || $instrument instanceof UpDownCounter
                        ? $instrument->add($record['value'], $attributes)
                        : $instrument->record($record['value'], $attributes);
                }
                $collected = [$telemetry->collect(), $telemetry->collect()];

                return [
                    'collected' => $collected,
                    'held' => $telemetry instanceof Test ? self::dump($telemetry) : array_map(self::decode(...), $payloads->getArrayCopy()),
                ];
            },
            'transport.new' => fn (array $a, Session $s) => $s->handle(self::transport($a['endpoint'], $a)),
            'transport.content_type' => fn (array $a, Session $s) => self::transportOf($a, $s)->contentType(),
            'transport.shutdown' => fn (array $a, Session $s) => self::coroutine(fn () => self::transportOf($a, $s)->shutdown()),
            'transport.force_flush' => fn (array $a, Session $s) => self::coroutine(fn () => self::transportOf($a, $s)->forceFlush()),
            'transport.send' => fn (array $a, Session $s) => self::coroutine(fn () => self::transportOf($a, $s)->send($a['payload'])->await()),
            'transport.exchange' => fn (array $a, Session $s) => self::coroutine(fn () => self::exchange($a)),
        ];
    }

    /** @var array<int, \ArrayObject<int, string>> */
    private static array $payloads = [];

    /**
     * An OpenTelemetry backend whose transport keeps every payload in `$payloads`.
     *
     * @param \ArrayObject<int, string> $payloads
     */
    private static function otlp(\ArrayObject $payloads, string $namespace = 'namespace', string $service = 'service', string $instance = 'instance'): OpenTelemetry
    {
        $transport = new class ($payloads) implements TransportInterface {
            /** @param \ArrayObject<int, string> $payloads */
            public function __construct(private \ArrayObject $payloads)
            {
            }

            public function contentType(): string
            {
                return ContentTypes::PROTOBUF;
            }

            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                $this->payloads[] = $payload;

                return new CompletedFuture(null);
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };

        return new OpenTelemetry('http://localhost:4318/v1/metrics', $namespace, $service, $instance, $transport);
    }

    private static function instrument(Telemetry $t, array $a): Counter|UpDownCounter|Histogram|Gauge|ObservableGauge
    {
        $unit = $a['unit'] ?? null;
        $description = $a['description'] ?? null;
        $advisory = isset($a['boundaries']) ? ['ExplicitBucketBoundaries' => $a['boundaries']] : [];
        $lazy = $a['lazy'] ?? false;

        return match ($a['kind']) {
            'counter' => $lazy ? Counter::lazy($t, $a['name'], $unit, $description) : $t->createCounter($a['name'], $unit, $description),
            'up_down_counter' => $lazy ? UpDownCounter::lazy($t, $a['name'], $unit, $description) : $t->createUpDownCounter($a['name'], $unit, $description),
            'histogram' => $lazy ? Histogram::lazy($t, $a['name'], $unit, $description, $advisory) : $t->createHistogram($a['name'], $unit, $description, $advisory),
            'gauge' => $lazy ? Gauge::lazy($t, $a['name'], $unit, $description) : $t->createGauge($a['name'], $unit, $description),
            'observable_gauge' => $t->createObservableGauge($a['name'], $unit, $description),
            default => throw new Fault('unknown instrument kind'),
        };
    }

    /**
     * @param array<mixed>|\stdClass $pairs [key, value] pairs, or a map
     * @return array<mixed>
     */
    private static function attributes(array|\stdClass $pairs): array
    {
        if ($pairs instanceof \stdClass) {
            return [];
        }
        if (!array_is_list($pairs)) {
            return $pairs;
        }
        $attributes = [];
        foreach ($pairs as [$key, $value]) {
            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /**
     * What the Test backend holds: each kind as [name, values] pairs, observable gauges as [name, callbacks].
     */
    private static function dump(Test $t): array
    {
        $pairs = static fn (array $instruments) => array_map(null, array_keys($instruments), array_map(static fn ($i) => $i->values, array_values($instruments)));

        return [
            'counters' => $t->counters === [] ? [] : $pairs($t->counters),
            'upDownCounters' => $t->upDownCounters === [] ? [] : $pairs($t->upDownCounters),
            'histograms' => $t->histograms === [] ? [] : $pairs($t->histograms),
            'gauges' => $t->gauges === [] ? [] : $pairs($t->gauges),
            'observableGauges' => array_map(null, array_keys($t->observableGauges), array_map(static fn ($g) => \count(self::callbacks($g)), array_values($t->observableGauges))) ?: [],
        ];
    }

    /**
     * An OTLP export request as data: everything but its timestamps, which are
     * reported as whether they are set.
     */
    /**
     * The elements of a protobuf repeated field.
     *
     * @return list<mixed>
     */
    private static function items(mixed $repeated): array
    {
        $out = [];
        if (is_iterable($repeated)) {
            foreach ($repeated as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function decode(string $payload): array
    {
        $request = new ExportMetricsServiceRequest();
        $request->mergeFromString($payload);
        $out = [];
        foreach (self::items($request->getResourceMetrics()) as $rm) {
            $scopes = [];
            foreach (self::items($rm->getScopeMetrics()) as $sm) {
                $metrics = [];
                foreach (self::items($sm->getMetrics()) as $metric) {
                    $m = ['name' => $metric->getName(), 'unit' => $metric->getUnit(), 'description' => $metric->getDescription(), 'data' => $metric->getData()];
                    $m += match ($metric->getData()) {
                        'sum' => ['monotonic' => $metric->getSum()->getIsMonotonic(), 'temporality' => $metric->getSum()->getAggregationTemporality(), 'points' => array_map(self::numberPoint(...), self::items($metric->getSum()->getDataPoints()))],
                        'gauge' => ['points' => array_map(self::numberPoint(...), self::items($metric->getGauge()->getDataPoints()))],
                        'histogram' => ['temporality' => $metric->getHistogram()->getAggregationTemporality(), 'points' => array_map(self::histogramPoint(...), self::items($metric->getHistogram()->getDataPoints()))],
                        default => [],
                    };
                    $metrics[] = $m;
                }
                $scopes[] = ['name' => $sm->getScope()?->getName(), 'version' => $sm->getScope()?->getVersion(), 'schema_url' => $sm->getSchemaUrl(), 'metrics' => $metrics];
            }
            $out[] = ['resource' => self::keyValues($rm->getResource()?->getAttributes() ?? []), 'schema_url' => $rm->getSchemaUrl(), 'scopes' => $scopes];
        }

        return $out;
    }

    private static function numberPoint(NumberDataPoint $p): array
    {
        return [
            'attributes' => self::keyValues($p->getAttributes()),
            'value' => $p->getValue() === 'as_int' ? (int) $p->getAsInt() : $p->getAsDouble(),
            'start' => (int) $p->getStartTimeUnixNano() !== 0,
            'time' => (int) $p->getTimeUnixNano() !== 0,
            'exemplars' => \count(self::items($p->getExemplars())),
        ];
    }

    private static function histogramPoint(HistogramDataPoint $p): array
    {
        return [
            'attributes' => self::keyValues($p->getAttributes()),
            'count' => (int) $p->getCount(),
            'sum' => $p->getSum(),
            'min' => $p->getMin(),
            'max' => $p->getMax(),
            'buckets' => array_map('intval', self::items($p->getBucketCounts())),
            'bounds' => self::items($p->getExplicitBounds()),
            'start' => (int) $p->getStartTimeUnixNano() !== 0,
            'time' => (int) $p->getTimeUnixNano() !== 0,
            'exemplars' => \count(self::items($p->getExemplars())),
        ];
    }

    /**
     * @param iterable<KeyValue> $attributes
     * @return list<array{0: string, 1: mixed}>
     */
    private static function keyValues(iterable $attributes): array
    {
        $out = [];
        foreach (self::items($attributes) as $kv) {
            $out[] = [$kv->getKey(), self::anyValue($kv->getValue())];
        }

        return $out;
    }

    private static function anyValue(?AnyValue $v): mixed
    {
        return match ($v?->getValue()) {
            'string_value' => $v->getStringValue(),
            'bool_value' => $v->getBoolValue(),
            'int_value' => (int) $v->getIntValue(),
            'double_value' => $v->getDoubleValue(),
            default => null,
        };
    }

    private static function transport(string $endpoint, array $a): Swoole
    {
        return new Swoole(
            $endpoint,
            ...(isset($a['content_type']) ? ['contentType' => $a['content_type']] : []),
            ...(isset($a['headers']) ? ['headers' => self::attributes($a['headers'])] : []),
            ...(isset($a['timeout']) ? ['timeout' => (float) $a['timeout']] : []),
            ...(isset($a['pool_size']) ? ['poolSize' => $a['pool_size']] : []),
            ...(isset($a['socket_buffer_size']) ? ['socketBufferSize' => $a['socket_buffer_size']] : []),
        );
    }

    /**
     * Runs a scripted collector on a local port and sends `sends` through a
     * transport to it, one after the other (or all at once with
     * `concurrent`). The collector answers request i with script[i] (the
     * last entry repeats): `{status, body, delay_ms}`, `{reset: true}` (drop
     * the connection without answering) or `{hang_ms}` (answer nothing for
     * that long, then drop it).
     *
     * @return array{results: list<mixed>, requests: list<array{payload: string, headers: list<array{0: string, 1: string}>}>}
     */
    private static function exchange(array $a): array
    {
        $requests = [];
        $script = $a['script'] ?? [['status' => 200, 'body' => '']];
        $server = new TcpServer('127.0.0.1', 0);
        $port = $server->port;
        $server->handle(function (TcpConnection $connection) use (&$requests, $script): void {
            $socket = $connection->exportSocket();
            $buffer = '';
            while (\is_string($chunk = $socket->recv(65536, 5.0)) && $chunk !== '') {
                $buffer .= $chunk;
                while (($request = self::takeRequest($buffer)) !== null) {
                    $requests[] = $request;
                    $step = $script[min(\count($requests), \count($script)) - 1];
                    if ($step['reset'] ?? false) {
                        $socket->setOption(SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                        $socket->close();

                        return;
                    }
                    if (isset($step['hang_ms'])) {
                        Coroutine::sleep($step['hang_ms'] / 1000);
                        $socket->close();

                        return;
                    }
                    if (isset($step['delay_ms'])) {
                        Coroutine::sleep($step['delay_ms'] / 1000);
                    }
                    $body = $step['body'] ?? '';
                    $socket->send("HTTP/1.1 {$step['status']} X\r\nContent-Length: " . \strlen($body) . "\r\n\r\n{$body}");
                }
            }
        });
        Coroutine::create(fn () => $server->start());
        Coroutine::sleep(0.02);
        $transport = self::transport($a['endpoint'] ?? "http://127.0.0.1:{$port}/v1/metrics", $a);
        $send = static function (string $payload) use ($transport): mixed {
            try {
                return $transport->send($payload)->await();
            } catch (\Throwable $e) {
                return ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
            }
        };
        $results = [];
        if ($a['concurrent'] ?? false) {
            $group = new Coroutine\WaitGroup();
            foreach ($a['sends'] as $i => $payload) {
                $group->add();
                Coroutine::create(function () use ($send, $payload, $i, &$results, $group): void {
                    $results[$i] = $send($payload);
                    $group->done();
                });
            }
            $group->wait();
            ksort($results);
        } else {
            foreach ($a['sends'] as $payload) {
                $results[] = $send($payload);
            }
        }
        $transport->shutdown();
        $server->shutdown();
        if ($a['concurrent'] ?? false) {
            usort($requests, static fn (array $x, array $y) => strcmp($x['payload'], $y['payload']));
        }

        return ['results' => array_values($results), 'requests' => $requests];
    }

    /**
     * One complete HTTP request off the front of `$buffer`: its body and its
     * headers (names lowercased, in order, without Host), or null while it is
     * still arriving.
     *
     * @return array{payload: string, headers: list<array{0: string, 1: string}>}|null
     */
    private static function takeRequest(string &$buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            return null;
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        array_shift($lines);
        $headers = [];
        $length = 0;
        foreach ($lines as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $name = strtolower($name);
            if ($name === 'content-length') {
                $length = (int) $value;
            }
            if (\in_array($name, ['content-type', 'content-length', 'authorization', 'x-custom-header'], true)) {
                $headers[] = [$name, $value];
            }
        }
        if (\strlen($buffer) < $end + 4 + $length) {
            return null;
        }
        $payload = substr($buffer, $end + 4, $length);
        $buffer = substr($buffer, $end + 4 + $length);
        sort($headers);

        return ['payload' => $payload, 'headers' => $headers];
    }

    private static function coroutine(callable $fn): mixed
    {
        $result = null;
        $error = null;
        Coroutine\run(function () use ($fn, &$result, &$error): void {
            try {
                $result = $fn();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    private static function telemetry(array $a, Session $s): Telemetry
    {
        $t = $s->get($a['telemetry']);

        return $t instanceof Telemetry ? $t : throw new Fault('not a telemetry backend');
    }

    private static function memory(array $a, Session $s): Test
    {
        $t = $s->get($a['telemetry']);

        return $t instanceof Test ? $t : throw new Fault('not a Test backend');
    }

    private static function transportOf(array $a, Session $s): Swoole
    {
        $t = $s->get($a['transport']);

        return $t instanceof Swoole ? $t : throw new Fault('not a transport');
    }
}
