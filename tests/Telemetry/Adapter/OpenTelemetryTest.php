<?php

declare(strict_types=1);

namespace Tests\Telemetry\Adapter;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\OpenTelemetry;

final class OpenTelemetryTest extends TestCase
{
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
