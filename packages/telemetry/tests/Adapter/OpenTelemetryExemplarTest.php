<?php

declare(strict_types=1);

namespace Utopia\Telemetry\Tests\Adapter;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\OpenTelemetry;

final class OpenTelemetryExemplarTest extends TestCase
{
    /**
     * The SDK's default exemplar filter keeps a data point for every record made under a sampled
     * span. Nothing that uses this adapter exports traces, so those exemplars only cost CPU on every
     * record. Records made under a sampled span must therefore export without exemplars.
     */
    public function testRecordsUnderSampledSpanExportNoExemplars(): void
    {
        $payloads = [];
        $telemetry = new OpenTelemetry(
            'http://localhost:4318/v1/metrics',
            'namespace',
            'service',
            'instance',
            $this->transport($payloads),
        );
        $histogram = $telemetry->createHistogram('cache.operation.duration', 's');
        $counter = $telemetry->createCounter('cache.load.total');

        $sampled = Span::wrap(SpanContext::create(
            '0af7651916cd43dd8448eb211c80319c',
            'b7ad6b7169203331',
            TraceFlags::SAMPLED,
        ));
        $scope = $sampled->storeInContext(Context::getCurrent())->activate();
        try {
            $this->assertTrue(Span::getCurrent()->getContext()->isSampled());
            $histogram->record(0.002, ['operation' => 'load']);
            $counter->add(1, ['result' => 'hit']);
        } finally {
            $scope->detach();
        }

        $this->assertTrue($telemetry->collect());
        $this->assertCount(1, $payloads);

        $request = new ExportMetricsServiceRequest();
        $request->mergeFromString($payloads[0]);
        $seen = [];
        foreach ($request->getResourceMetrics() as $resource) {
            foreach ($resource->getScopeMetrics() as $scope) {
                foreach ($scope->getMetrics() as $metric) {
                    $points = match ($metric->getName()) {
                        'cache.operation.duration' => $metric->getHistogram()->getDataPoints(),
                        'cache.load.total' => $metric->getSum()->getDataPoints(),
                    };
                    foreach ($points as $point) {
                        $seen[] = $metric->getName();
                        $this->assertCount(0, $point->getExemplars(), $metric->getName() . ' exported an exemplar');
                    }
                }
            }
        }
        sort($seen);
        $this->assertSame(['cache.load.total', 'cache.operation.duration'], $seen);
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
            public function __construct(private \Closure $capture)
            {
            }

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
