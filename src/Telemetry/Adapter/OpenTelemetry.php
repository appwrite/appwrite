<?php

namespace Utopia\Telemetry\Adapter;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\MetricReaderInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SemConv\ResourceAttributes;
use Utopia\Telemetry\Adapter;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

class OpenTelemetry implements Adapter
{
    private MetricReaderInterface $reader;

    private MeterInterface $meter;

    /**
     * @var array<class-string, array<string, Counter|UpDownCounter|Histogram|Gauge|ObservableGauge>>
     */
    private array $meterStorage = [
        Counter::class => [],
        UpDownCounter::class => [],
        Histogram::class => [],
        Gauge::class => [],
        ObservableGauge::class => [],
    ];

    /**
     * @param string $endpoint
     * @param string $serviceNamespace
     * @param string $serviceName
     * @param string $serviceInstanceId
     * @param TransportInterface<string>|null $transport
     */
    public function __construct(
        string $endpoint,
        string $serviceNamespace,
        string $serviceName,
        string $serviceInstanceId,
        protected ?TransportInterface $transport = null,
    ) {
        if ($this->transport === null) {
            $this->transport = (new OtlpHttpTransportFactory())
                ->create($endpoint, ContentTypes::PROTOBUF);
        }

        $exporter = $this->createExporter($this->transport);

        $attributes = Attributes::create([
            'service.namespace' => $serviceNamespace,
            'service.name' => $serviceName,
            'service.instance.id' => $serviceInstanceId,
        ]);

        $this->meter = $this->initMeter($exporter, $attributes);
    }

    /**
     * Initialize Meter
     *
     * @param AttributesInterface<string, mixed> $attributes
     */
    protected function initMeter(MetricExporterInterface $exporter, AttributesInterface $attributes): MeterInterface
    {
        $this->reader = new ExportingReader($exporter);
        $meterProvider = MeterProvider::builder()
            ->setResource(ResourceInfo::create($attributes, ResourceAttributes::SCHEMA_URL))
            ->addReader($this->reader)
            ->build();

        Sdk::builder()->setMeterProvider($meterProvider)->buildAndRegisterGlobal();

        return $meterProvider->getMeter('cloud');
    }

    /**
     * Create Metric Exporter
     *
     * @param TransportInterface<string> $transport
     */
    protected function createExporter(TransportInterface $transport): MetricExporterInterface
    {
        /** @phpstan-ignore argument.type */
        return new MetricExporter($transport, Temporality::CUMULATIVE);
    }

    /**
     * @template T of Counter|UpDownCounter|Histogram|Gauge|ObservableGauge
     * @param class-string<T> $type
     * @param callable(): T $creator
     * @return T
     */
    private function createMeter(string $type, string $name, callable $creator): Counter|UpDownCounter|Histogram|Gauge|ObservableGauge
    {
        if (! isset($this->meterStorage[$type][$name])) {
            $this->meterStorage[$type][$name] = $creator();
        }

        /** @var T */
        return $this->meterStorage[$type][$name];
    }

    /**
     * Create a Counter metric
     *
     * @param array<string, mixed> $advisory
     */
    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        return $this->createMeter(Counter::class, $name, function () use ($name, $unit, $description, $advisory) {
            $counter = $this->meter->createCounter($name, $unit, $description, $advisory);

            return new class ($counter) extends Counter {
                public function __construct(private CounterInterface $counter) {}

                /**
                 * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
                 */
                public function add(float|int $amount, iterable $attributes = []): void
                {
                    $this->counter->add($amount, $attributes);
                }
            };
        });
    }

    /**
     * Create a Histogram metric
     *
     * @param array<string, mixed> $advisory
     */
    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        return $this->createMeter(Histogram::class, $name, function () use ($name, $unit, $description, $advisory) {
            $histogram = $this->meter->createHistogram($name, $unit, $description, $advisory);

            return new class ($histogram) extends Histogram {
                public function __construct(private HistogramInterface $histogram) {}

                /**
                 * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
                 */
                public function record(float|int $amount, iterable $attributes = []): void
                {
                    $this->histogram->record($amount, $attributes);
                }
            };
        });
    }

    /**
     * Create a Gauge metric
     *
     * @param array<string, mixed> $advisory
     */
    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        return $this->createMeter(Gauge::class, $name, function () use ($name, $unit, $description, $advisory) {
            $gauge = $this->meter->createGauge($name, $unit, $description, $advisory);

            return new class ($gauge) extends Gauge {
                public function __construct(private GaugeInterface $gauge) {}

                /**
                 * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
                 */
                public function record(float|int $amount, iterable $attributes = []): void
                {
                    $this->gauge->record($amount, $attributes);
                }
            };
        });
    }

    /**
     * Create an UpDownCounter metric
     *
     * @param array<string, mixed> $advisory
     */
    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        return $this->createMeter(UpDownCounter::class, $name, function () use ($name, $unit, $description, $advisory) {
            $upDownCounter = $this->meter->createUpDownCounter($name, $unit, $description, $advisory);

            return new class ($upDownCounter) extends UpDownCounter {
                public function __construct(private UpDownCounterInterface $upDownCounter) {}

                /**
                 * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
                 */
                public function add(float|int $amount, iterable $attributes = []): void
                {
                    $this->upDownCounter->add($amount, $attributes);
                }
            };
        });
    }

    /**
     * Create an ObservableGauge metric
     *
     * @param array<string, mixed> $advisory
     */
    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
    {
        return $this->createMeter(ObservableGauge::class, $name, function () use ($name, $unit, $description, $advisory) {
            $otelGauge = $this->meter->createObservableGauge($name, $unit, $description, $advisory);

            return new class ($otelGauge) extends ObservableGauge {
                /** @var list<\Closure> */
                private array $callbacks = [];

                public function __construct(private \OpenTelemetry\API\Metrics\ObservableGaugeInterface $gauge)
                {
                    $this->gauge->observe(function (ObserverInterface $observer): void {
                        $observe = function (float|int $value, iterable $attributes = []) use ($observer): void {
                            /** @var iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes */
                            $observer->observe($value, $attributes);
                        };
                        foreach ($this->callbacks as $callback) {
                            $callback($observe);
                        }
                    });
                }

                public function observe(callable $callback): void
                {
                    $this->callbacks[] = \Closure::fromCallable($callback);
                }
            };
        });
    }

    /**
     * Collect and export metrics
     */
    public function collect(): bool
    {
        return $this->reader->collect();
    }
}
