<?php

/**
 * A DNS server for the compat cases: `php server.php <config.json>`.
 *
 * The config names the adapter (native or swoole), the transports and
 * their ports, the resolver and the random seed. What the server reports
 * (errors, worker starts, telemetry) is appended as JSON lines to files in
 * the config's state directory, for Adapter.php to read back. The Rust
 * twin is `fixture` in crates/tools/compat/src/libs/dns.rs.
 */

require '/usr/src/code/vendor/autoload.php';
require __DIR__ . '/Fixture.php';

use Tests\Compat\Dns\Fixture;
use Utopia\DNS\Adapter\Native;
use Utopia\DNS\Adapter\Swoole;
use Utopia\DNS\Server;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

$config = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$state = $config['state'];
$log = static function (string $file, mixed $entry) use ($state): void {
    file_put_contents($state . '/' . $file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) . "\n", FILE_APPEND | LOCK_EX);
};

$native = ($config['adapter'] ?? 'native') === 'native';
$host = $config['host'] ?? '127.0.0.1';
$ports = $config['ports'];
$tcp = $config['tcp'] ?? [];
$transports = [];
if (isset($ports['udp'])) {
    $transports[] = $native ? new Native\Udp($host, $ports['udp']) : new Swoole\Udp($host, $ports['udp']);
}
if (isset($ports['udp53'])) {
    $transports[] = $native ? new Native\Udp('127.0.0.1', 53) : new Swoole\Udp('127.0.0.1', 53);
}
foreach (['tcp' => false, 'proxy' => true] as $kind => $proxy) {
    if (!isset($ports[$kind])) {
        continue;
    }
    $transports[] = $native
        ? new Native\Tcp(
            $host,
            $ports[$kind],
            maxClients: $tcp['maxClients'] ?? 100,
            maxBufferSize: $tcp['maxBufferSize'] ?? 16384,
            maxFrameSize: $tcp['maxFrameSize'] ?? 65535,
            idleTimeout: $tcp['idleTimeout'] ?? 30,
            proxyProtocol: $proxy,
        )
        : new Swoole\Tcp($host, $ports[$kind], proxyProtocol: $proxy);
}
if (isset($ports['http'])) {
    $transports[] = new Swoole\Http($host, $ports['http'], trustProxy: $config['trustProxy'] ?? false);
}

$adapter = $native ? new Native($transports) : new Swoole($transports, workers: 1, idleTimeout: $tcp['idleTimeout'] ?? 30);
$server = new Server($adapter, Fixture::resolver($config['resolver'], $config['upstream'] ?? null));
$server->setDebug(false);

if ($config['telemetry'] ?? false) {
    $server->setTelemetry(new class ($log) implements Telemetry {
        public function __construct(private \Closure $log)
        {
        }

        public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
        {
            return new class ($this->log, $name) extends Counter {
                public function __construct(private \Closure $log, private string $name)
                {
                }

                public function add(float|int $amount, iterable $attributes = []): void
                {
                    ($this->log)('telemetry', ['counter', $this->name, $amount, iterator_to_array($attributes)]);
                }
            };
        }

        public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
        {
            return new class ($this->log, $name, $unit, $advisory) extends Histogram {
                public function __construct(private \Closure $log, private string $name, private ?string $unit, private array $advisory)
                {
                }

                public function record(float|int $amount, iterable $attributes = []): void
                {
                    ($this->log)('telemetry', ['histogram', $this->name, $this->unit, array_map(floatval(...), $this->advisory['ExplicitBucketBoundaries'] ?? []), iterator_to_array($attributes)]);
                }
            };
        }

        public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
        {
            throw new \LogicException('unused');
        }

        public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
        {
            throw new \LogicException('unused');
        }

        public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
        {
            throw new \LogicException('unused');
        }

        public function collect(): bool
        {
            return true;
        }
    });
}

$server->error(static function (\Throwable $error) use ($log): void {
    $log('errors', ['class' => $error::class, 'message' => $error->getMessage()]);
});
$server->onWorkerStart(static function (Server $server, int $workerId) use ($log, $config): void {
    // Answers are shuffled with the global Mersenne Twister, as Zone\Resolver does.
    mt_srand($config['seed'] ?? 1);
    $log('workers', $workerId);
});

$server->start();
