<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\GEOSMS;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Messaging\Messages\SMS;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

final class TelemetryTest extends Base
{
    public function testRecordsSuccessesAndFailures(): void
    {
        $telemetry = new RecordingTelemetry();
        $adapter = new TelemetryAdapter([
            'deliveredTo' => 2,
            'type' => 'sms',
            'results' => [
                ['recipient' => '+1', 'status' => 'success', 'error' => ''],
                ['recipient' => '+2', 'status' => 'success', 'error' => ''],
                ['recipient' => '+3', 'status' => 'failure', 'error' => 'Nope'],
            ],
        ]);
        $adapter->setTelemetry($telemetry);

        $adapter->send(new SMS(['+1', '+2', '+3'], 'Hello')->setOrigin('external'));

        $this->assertSame([
            ['amount' => 2, 'attributes' => ['result' => 'success', 'origin' => 'external', 'type' => 'sms', 'provider' => 'test']],
            ['amount' => 1, 'attributes' => ['result' => 'failure', 'origin' => 'external', 'type' => 'sms', 'provider' => 'test']],
        ], $telemetry->records);
    }

    public function testRecordsThrownSendAsFailure(): void
    {
        $telemetry = new RecordingTelemetry();
        $adapter = new TelemetryAdapter(null, new \Exception('Provider failed'));
        $adapter->setTelemetry($telemetry);

        $this->expectException(\Exception::class);

        try {
            $adapter->send(new SMS(['+1', '+2'], 'Hello'));
        } finally {
            $this->assertSame([
                ['amount' => 2, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test']],
            ], $telemetry->records);
        }
    }

    public function testRecordsCountsFromResults(): void
    {
        $telemetry = new RecordingTelemetry();
        $adapter = new TelemetryAdapter([
            'deliveredTo' => 99,
            'type' => 'sms',
            'results' => [
                ['recipient' => '+1', 'status' => 'success', 'error' => ''],
                ['recipient' => '+2', 'status' => 'pending', 'error' => ''],
            ],
        ]);
        $adapter->setTelemetry($telemetry);

        $adapter->send(new SMS(['+1', '+2'], 'Hello'));

        $this->assertSame([
            ['amount' => 1, 'attributes' => ['result' => 'success', 'type' => 'sms', 'provider' => 'test']],
            ['amount' => 1, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test']],
        ], $telemetry->records);
    }

    public function testGeosmsPropagatesTelemetryToLocalAdapters(): void
    {
        $telemetry = new RecordingTelemetry();
        $default = new TelemetryAdapter([
            'deliveredTo' => 0,
            'type' => 'sms',
            'results' => [],
        ]);
        $local = new TelemetryAdapter([
            'deliveredTo' => 1,
            'type' => 'sms',
            'results' => [
                ['recipient' => '+911234567890', 'status' => 'success', 'error' => ''],
            ],
        ]);

        $adapter = new GEOSMS($default);
        $adapter->setTelemetry($telemetry);
        $adapter->setLocal(CallingCode::INDIA, $local);
        $adapter->send(new SMS(['+911234567890'], 'Hello')->setOrigin('internal'));

        $this->assertSame([
            ['amount' => 1, 'attributes' => ['result' => 'success', 'origin' => 'internal', 'type' => 'sms', 'provider' => 'test']],
        ], $telemetry->records);
    }

    public function testDefaultTelemetryDoesNothing(): void
    {
        $adapter = new TelemetryAdapter([
            'deliveredTo' => 1,
            'type' => 'sms',
            'results' => [
                ['recipient' => '+1', 'status' => 'success', 'error' => ''],
            ],
        ]);

        $response = $adapter->send(new SMS(['+1'], 'Hello'));

        $this->assertSame(1, $response['deliveredTo']);
    }
}

class TelemetryAdapter extends SMSAdapter
{
    /**
     * @param array<string, mixed>|null $response
     */
    public function __construct(
        private readonly ?array $response = null,
        private readonly ?\Throwable $error = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'Test';
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 100;
    }

    /**
     * @return array<string, mixed>
     */
    protected function process(SMS $message): array
    {
        if ($this->error instanceof \Throwable) {
            throw $this->error;
        }

        return $this->response ?? [
            'deliveredTo' => 0,
            'type' => 'sms',
            'results' => [],
        ];
    }
}

class RecordingTelemetry implements Telemetry
{
    /**
     * @var array<int, array{amount: int|float, attributes: array<string, mixed>}>
     */
    public array $records = [];

    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        return new class ($this) extends Counter {
            public function __construct(private readonly RecordingTelemetry $telemetry) {}

            public function add(float|int $amount, iterable $attributes = []): void
            {
                $this->telemetry->records[] = [
                    'amount' => $amount,
                    'attributes' => iterator_to_array((function () use ($attributes) {
                        yield from $attributes;
                    })()),
                ];
            }
        };
    }

    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        throw new \BadMethodCallException();
    }

    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        throw new \BadMethodCallException();
    }

    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        throw new \BadMethodCallException();
    }

    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
    {
        throw new \BadMethodCallException();
    }

    public function collect(): bool
    {
        return true;
    }
}
