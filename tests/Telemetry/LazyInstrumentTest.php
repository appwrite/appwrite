<?php

declare(strict_types=1);

namespace Tests\Telemetry;

use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\Test;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\UpDownCounter;

final class LazyInstrumentTest extends TestCase
{
    public function testLazyCounterCreatesInnerCounterOnFirstAdd(): void
    {
        $telemetry = new Test();
        $counter = Counter::lazy($telemetry, 'events.total', '{event}', 'Event count');

        $this->assertSame([], $telemetry->counters);

        $counter->add(1, ['event.name' => 'created']);

        $this->assertArrayHasKey('events.total', $telemetry->counters);
        $this->assertSame([1], $telemetry->counters['events.total']->values);

        $inner = $telemetry->counters['events.total'];
        $counter->add(2);

        $this->assertSame($inner, $telemetry->counters['events.total']);
        $this->assertSame([1, 2], $telemetry->counters['events.total']->values);
    }

    public function testLazyGaugeCreatesInnerGaugeOnFirstRecord(): void
    {
        $telemetry = new Test();
        $gauge = Gauge::lazy($telemetry, 'event.timestamp', 's', 'Event timestamp');

        $this->assertSame([], $telemetry->gauges);

        $gauge->record(123.45, ['event.name' => 'transition']);

        $this->assertArrayHasKey('event.timestamp', $telemetry->gauges);
        $this->assertSame([123.45], $telemetry->gauges['event.timestamp']->values);

        $inner = $telemetry->gauges['event.timestamp'];
        $gauge->record(456.78);

        $this->assertSame($inner, $telemetry->gauges['event.timestamp']);
        $this->assertSame([123.45, 456.78], $telemetry->gauges['event.timestamp']->values);
    }

    public function testLazyHistogramCreatesInnerHistogramOnFirstRecord(): void
    {
        $telemetry = new Test();
        $histogram = Histogram::lazy($telemetry, 'request.duration', 'ms', 'Request duration');

        $this->assertSame([], $telemetry->histograms);

        $histogram->record(12.3, ['route' => '/v1/health']);

        $this->assertArrayHasKey('request.duration', $telemetry->histograms);
        $this->assertSame([12.3], $telemetry->histograms['request.duration']->values);

        $inner = $telemetry->histograms['request.duration'];
        $histogram->record(45.6);

        $this->assertSame($inner, $telemetry->histograms['request.duration']);
        $this->assertSame([12.3, 45.6], $telemetry->histograms['request.duration']->values);
    }

    public function testLazyUpDownCounterCreatesInnerCounterOnFirstAdd(): void
    {
        $telemetry = new Test();
        $counter = UpDownCounter::lazy($telemetry, 'active.requests', '{request}', 'Active requests');

        $this->assertSame([], $telemetry->upDownCounters);

        $counter->add(1, ['route' => '/v1/health']);

        $this->assertArrayHasKey('active.requests', $telemetry->upDownCounters);
        $this->assertSame([1], $telemetry->upDownCounters['active.requests']->values);

        $inner = $telemetry->upDownCounters['active.requests'];
        $counter->add(-1);

        $this->assertSame($inner, $telemetry->upDownCounters['active.requests']);
        $this->assertSame([1, -1], $telemetry->upDownCounters['active.requests']->values);
    }
}
