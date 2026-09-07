<?php

declare(strict_types=1);

namespace Tests\Telemetry\Adapter;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\OpenTelemetry;

final class OpenTelemetryTest extends TestCase
{
    public function testEmptyObservationsDoNotDiscardRequestMetrics(): void
    {
        $payloads = [];
        $telemetry = new OpenTelemetry(
            'http://localhost:4318/v1/metrics',
            'edge',
            'edge-databases',
            'instance',
            $this->transport($payloads),
        );
        $counter = $telemetry->createCounter('edge.database.http.request');
        $observations = [];
        $telemetry->createObservableGauge('edge.database.backup.schedule.missed')
            ->observe(function (callable $observe) use (&$observations): void {
                foreach ($observations as $value) {
                    $observe($value);
                }
            });

        // The gauge starts empty, reports a real zero, becomes empty, then reports again.
        foreach ([[], [0], [], [2]] as $index => $values) {
            $observations = $values;
            $counter->add(1);
            $this->assertTrue($telemetry->collect());
            $request = new ExportMetricsServiceRequest();
            $request->mergeFromString($payloads[$index]);
            $metrics = [];
            foreach ($request->getResourceMetrics() as $resource) {
                foreach ($resource->getScopeMetrics() as $scope) {
                    foreach ($scope->getMetrics() as $metric) {
                        $metrics[$metric->getName()] = $metric;
                    }
                }
            }
            $this->assertCount($values === [] ? 1 : 2, $metrics);
            $this->assertSame($index + 1, (int) $metrics['edge.database.http.request']->getSum()->getDataPoints()[0]->getAsInt());
            if ($values !== []) {
                $this->assertSame($values[0], (int) $metrics['edge.database.backup.schedule.missed']->getGauge()->getDataPoints()[0]->getAsInt());
            }
        }
    }

    public function testEmptyCollectionDoesNotSendAnOtlpRequest(): void
    {
        $payloads = [];
        $telemetry = new OpenTelemetry(
            'http://localhost:4318/v1/metrics',
            'edge',
            'edge-databases',
            'instance',
            $this->transport($payloads),
        );
        $telemetry->createObservableGauge('empty')->observe(static function (callable $observe): void {});

        $this->assertTrue($telemetry->collect());
        $this->assertSame([], $payloads);
    }

    /**
     * An instrument that has never recorded must not reach the wire at all: an exported metric with
     * zero data points makes Prometheus 3.13+ reject the entire OTLP batch, not just that metric.
     */
    public function testOnlyRecordedInstrumentsAreExported(): void
    {
        $payloads = [];
        $telemetry = new OpenTelemetry(
            'http://localhost:4318/v1/metrics',
            'namespace',
            'service',
            'instance',
            $this->transport($payloads),
        );

        $telemetry->createCounter('recorded.counter', '{event}')->add(1);
        $telemetry->createUpDownCounter('recorded.up_down_counter', '{request}')->add(1);
        $telemetry->createHistogram('recorded.histogram', 'ms')->record(12.3);
        $telemetry->createGauge('recorded.gauge', 's')->record(4.5);
        $telemetry->createObservableGauge('recorded.observable_gauge', '%')
            ->observe(fn(callable $observer) => $observer(72.4));

        $telemetry->createCounter('unused.counter', '{event}');
        $telemetry->createUpDownCounter('unused.up_down_counter', '{request}');
        $telemetry->createHistogram('unused.histogram', 'ms');
        $telemetry->createGauge('unused.gauge', 's');
        $telemetry->createObservableGauge('unused.observable_gauge', '%');

        $this->assertTrue($telemetry->collect());

        $exported = implode('', $payloads);
        foreach (['counter', 'up_down_counter', 'histogram', 'gauge', 'observable_gauge'] as $type) {
            $this->assertStringContainsString('recorded.' . $type, $exported);
            $this->assertStringNotContainsString('unused.' . $type, $exported);
        }
    }

    /**
     * @param list<string> $payloads
     * @return TransportInterface<string>
     */
    private function transport(array &$payloads): TransportInterface
    {
        $capture = function (string $payload) use (&$payloads): void {
            $payloads[] = $payload;
        };

        return new class ($capture) implements TransportInterface {
            public function __construct(private \Closure $capture) {}

            public function contentType(): string
            {
                return ContentTypes::PROTOBUF;
            }

            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                ($this->capture)($payload);

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
    }
}
