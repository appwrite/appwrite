<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\GEOSMS;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Messaging\Messages\Email;
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
                ['recipient' => '+14155550101', 'status' => 'success', 'error' => ''],
                ['recipient' => '+14155550102', 'status' => 'success', 'error' => ''],
                ['recipient' => '+14155550103', 'status' => 'failure', 'error' => 'Nope'],
            ],
        ]);
        $adapter->setTelemetry($telemetry);

        $adapter->send(new SMS(['+14155550101', '+14155550102', '+14155550103'], 'Hello')->setOrigin('external'));

        $this->assertSame([
            ['amount' => 2, 'attributes' => ['result' => 'success', 'origin' => 'external', 'type' => 'sms', 'provider' => 'test', 'country_code' => '1']],
            ['amount' => 1, 'attributes' => ['result' => 'failure', 'origin' => 'external', 'type' => 'sms', 'provider' => 'test', 'country_code' => '1']],
        ], $telemetry->records);
    }

    public function testRecordsThrownSendAsFailure(): void
    {
        $telemetry = new RecordingTelemetry();
        $adapter = new TelemetryAdapter(null, new \Exception('Provider failed'));
        $adapter->setTelemetry($telemetry);

        $this->expectException(\Exception::class);

        try {
            $adapter->send(new SMS(['+14155550101', '+14155550102'], 'Hello'));
        } finally {
            $this->assertSame([
                ['amount' => 2, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test', 'country_code' => '1']],
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
                ['recipient' => '+14155550101', 'status' => 'success', 'error' => ''],
                ['recipient' => '+14155550102', 'status' => 'pending', 'error' => ''],
            ],
        ]);
        $adapter->setTelemetry($telemetry);

        $adapter->send(new SMS(['+14155550101', '+14155550102'], 'Hello'));

        $this->assertSame([
            ['amount' => 1, 'attributes' => ['result' => 'success', 'type' => 'sms', 'provider' => 'test', 'country_code' => '1']],
            ['amount' => 1, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test', 'country_code' => '1']],
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
            ['amount' => 1, 'attributes' => ['result' => 'success', 'origin' => 'internal', 'type' => 'sms', 'provider' => 'test', 'country_code' => '91']],
        ], $telemetry->records);
    }

    public function testRecordsSmsCountryCodePerRecipient(): void
    {
        $telemetry = new RecordingTelemetry();
        $adapter = new TelemetryAdapter([
            'deliveredTo' => 2,
            'type' => 'sms',
            'results' => [
                ['recipient' => '+911234567890', 'status' => 'success', 'error' => ''],
                ['recipient' => '+14155550100', 'status' => 'failure', 'error' => 'Nope'],
                ['recipient' => '+919876543210', 'status' => 'failure', 'error' => 'Nope'],
                ['recipient' => 'not-a-number', 'status' => 'success', 'error' => ''],
            ],
        ]);
        $adapter->setTelemetry($telemetry);

        $adapter->send(new SMS(['+911234567890', '+14155550100', '+919876543210', 'not-a-number'], 'Hello'));

        $this->assertSame([
            ['amount' => 1, 'attributes' => ['result' => 'success', 'type' => 'sms', 'provider' => 'test', 'country_code' => '91']],
            ['amount' => 1, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test', 'country_code' => '91']],
            ['amount' => 1, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test', 'country_code' => '1']],
            ['amount' => 1, 'attributes' => ['result' => 'success', 'type' => 'sms', 'provider' => 'test', 'country_code' => 'unknown']],
        ], $telemetry->records);
    }

    public function testRecordsThrownSmsSendPerCountry(): void
    {
        $telemetry = new RecordingTelemetry();
        $adapter = new TelemetryAdapter(null, new \Exception('Provider failed'));
        $adapter->setTelemetry($telemetry);

        $this->expectException(\Exception::class);

        try {
            $adapter->send(new SMS(['+911234567890', '+14155550100', '+919876543210'], 'Hello'));
        } finally {
            $this->assertSame([
                ['amount' => 2, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test', 'country_code' => '91']],
                ['amount' => 1, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test', 'country_code' => '1']],
            ], $telemetry->records);
        }
    }

    public function testGeosmsRecordsEachBatchUnderItsCountry(): void
    {
        $telemetry = new RecordingTelemetry();
        $default = new TelemetryAdapter([
            'deliveredTo' => 0,
            'type' => 'sms',
            'results' => [
                ['recipient' => '+14155550100', 'status' => 'failure', 'error' => 'Nope'],
            ],
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
        $adapter->send(new SMS(['+911234567890', '+14155550100'], 'Hello'));

        $this->assertSame([
            ['amount' => 1, 'attributes' => ['result' => 'success', 'type' => 'sms', 'provider' => 'test', 'country_code' => '91']],
            ['amount' => 1, 'attributes' => ['result' => 'failure', 'type' => 'sms', 'provider' => 'test', 'country_code' => '1']],
        ], $telemetry->records);
    }

    public function testRecordsEmailTemplate(): void
    {
        $telemetry = new RecordingTelemetry();
        $adapter = new TelemetryEmailAdapter('success');
        $adapter->setTelemetry($telemetry);

        $adapter->send($this->email()->setOrigin('internal')->setTemplate('alert'));
        $adapter->send($this->email());

        $this->assertSame([
            ['amount' => 1, 'attributes' => ['result' => 'success', 'origin' => 'internal', 'type' => 'email', 'provider' => 'test', 'template' => 'alert']],
            ['amount' => 1, 'attributes' => ['result' => 'success', 'type' => 'email', 'provider' => 'test']],
        ], $telemetry->records);
    }

    public function testRecordsEmailTemplateOnFailure(): void
    {
        $telemetry = new RecordingTelemetry();
        $adapter = new TelemetryEmailAdapter('failure');
        $adapter->setTelemetry($telemetry);

        $adapter->send($this->email()->setTemplate('verification'));

        $this->assertSame([
            ['amount' => 1, 'attributes' => ['result' => 'failure', 'type' => 'email', 'provider' => 'test', 'template' => 'verification']],
        ], $telemetry->records);
    }

    public function testDefaultTelemetryDoesNothing(): void
    {
        $adapter = new TelemetryAdapter([
            'deliveredTo' => 1,
            'type' => 'sms',
            'results' => [
                ['recipient' => '+14155550101', 'status' => 'success', 'error' => ''],
            ],
        ]);

        $response = $adapter->send(new SMS(['+14155550101'], 'Hello'));

        $this->assertSame(1, $response['deliveredTo']);
    }

    private function email(): Email
    {
        return new Email(
            to: ['john@appwrite.io'],
            subject: 'Hello',
            content: 'Hello',
            fromName: 'Appwrite',
            fromEmail: 'team@appwrite.io',
        );
    }
}

class TelemetryEmailAdapter extends EmailAdapter
{
    public function __construct(private readonly string $status)
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'Test';
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1;
    }

    /**
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    protected function process(Email $message): array
    {
        return [
            'deliveredTo' => $this->status === 'success' ? 1 : 0,
            'type' => 'email',
            'results' => [
                ['recipient' => 'john@appwrite.io', 'status' => $this->status, 'error' => ''],
            ],
        ];
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
            public function __construct(private readonly RecordingTelemetry $telemetry)
            {
            }

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
