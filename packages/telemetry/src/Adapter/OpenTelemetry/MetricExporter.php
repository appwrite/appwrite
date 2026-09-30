<?php

declare(strict_types=1);

namespace Utopia\Telemetry\Adapter\OpenTelemetry;

use OpenTelemetry\Contrib\Otlp\MetricExporter as OtlpMetricExporter;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

/**
 * Empty observable collections must not invalidate the other metrics in an OTLP request.
 */
final class MetricExporter implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface
{
    public function __construct(private readonly OtlpMetricExporter $exporter)
    {
    }

    public function export(iterable $batch): bool
    {
        $metrics = [];
        foreach ($batch as $metric) {
            $data = $metric->data;
            if ($data instanceof Gauge) {
                $metric = $this->withoutStartTimestamp($metric, $data);
                $data = $metric->data;
            }
            if ($data instanceof Gauge || $data instanceof Histogram || $data instanceof Sum) {
                foreach ($data->dataPoints as $point) {
                    $metrics[] = $metric;
                    break;
                }
            } else {
                $metrics[] = $metric;
            }
        }

        return $metrics === [] || $this->exporter->export($metrics);
    }

    /**
     * A gauge has no start time in OTLP. The SDK sets one anyway, and Prometheus with
     * created-timestamp-zero-ingestion writes a 0 there, so every new process reports a false 0.
     */
    private function withoutStartTimestamp(Metric $metric, Gauge $gauge): Metric
    {
        $points = [];
        foreach ($gauge->dataPoints as $point) {
            $points[] = new NumberDataPoint($point->value, $point->attributes, 0, $point->timestamp, $point->exemplars);
        }

        return new Metric($metric->instrumentationScope, $metric->resource, $metric->name, $metric->unit, $metric->description, new Gauge($points));
    }

    public function temporality(MetricMetadataInterface $metric): Temporality|string|null
    {
        return $this->exporter->temporality($metric);
    }

    public function forceFlush(): bool
    {
        return $this->exporter->forceFlush();
    }

    public function shutdown(): bool
    {
        return $this->exporter->shutdown();
    }
}
