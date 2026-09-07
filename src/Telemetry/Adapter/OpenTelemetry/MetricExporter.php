<?php

declare(strict_types=1);

namespace Utopia\Telemetry\Adapter\OpenTelemetry;

use OpenTelemetry\Contrib\Otlp\MetricExporter as OtlpMetricExporter;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

/**
 * Empty observable collections must not invalidate the other metrics in an OTLP request.
 */
final class MetricExporter implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface
{
    public function __construct(private readonly OtlpMetricExporter $exporter) {}

    public function export(iterable $batch): bool
    {
        $metrics = [];
        foreach ($batch as $metric) {
            $data = $metric->data;
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
