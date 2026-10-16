<?php

declare(strict_types=1);

namespace Utopia\Storage\Tests\Device;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Telemetry;
use Utopia\Storage\Exception\NotFoundException;
use Utopia\Storage\Exception\RemoteException;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class S3StreamingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\Utopia\Client\Psr18\ResponseStreamingClientInterface::class)) {
            $this->markTestSkipped('Response-aware streaming requires the current client; run with --linked before its release.');
        }
    }

    public function testBytesReachSinkBeforeTheBackendFinishes(): void
    {
        $this->serve(function (Device $device, string $marker): void {
            $bytes = 0;
            $hash = hash_init('sha256');
            $baseline = memory_get_usage();
            memory_reset_peak_usage();
            $device->stream('/object', static function (string $chunk) use ($marker, &$bytes, $hash): void {
                file_put_contents($marker, 'read');
                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            });
            $peak = memory_get_peak_usage() - $baseline;
            $this->assertSame(8 * 1024 * 1024, $bytes);
            $this->assertLessThan(2 * 1024 * 1024, $peak);
            $this->assertSame(hash('sha256', str_repeat('x', $bytes)), hash_final($hash));
        });
    }

    public function testBackendErrorBytesNeverReachTheSink(): void
    {
        $this->serve(function (Device $device): void {
            $bytes = '';
            try {
                $device->stream('/missing', static function (string $chunk) use (&$bytes): void {
                    $bytes .= $chunk;
                });
                $this->fail('Expected a missing backend object');
            } catch (NotFoundException) {
            }
            $this->assertSame('', $bytes);
        });
    }

    public function testIgnoredRangeIsRejectedBeforeDelivery(): void
    {
        $this->serve(function (Device $device): void {
            $bytes = '';
            try {
                $device->stream('/ignores-range', static function (string $chunk) use (&$bytes): void {
                    $bytes .= $chunk;
                }, 2, 4);
                $this->fail('Expected the invalid backend range to be refused');
            } catch (RemoteException) {
            }
            $this->assertSame('', $bytes);
        });
    }

    public function testTruncatedBackendResponseIsNotReplayed(): void
    {
        $this->serve(function (Device $device, string $marker): void {
            $bytes = '';
            try {
                $device->stream('/truncated', static function (string $chunk) use (&$bytes): void {
                    $bytes .= $chunk;
                });
                $this->fail('Expected the truncated transfer to fail');
            } catch (\Utopia\Storage\Exception\TransportException) {
            }
            $this->assertSame(str_repeat('x', 12345), $bytes);
            $this->assertSame('1', file_get_contents($marker . '.requests'));
        });
    }

    public function testCancelledSinkStopsTheBackendRequestWithoutReplay(): void
    {
        $this->serve(function (Device $device, string $marker): void {
            $received = 0;
            $cancelled = new \RuntimeException('Downstream disconnected');
            try {
                $device->stream('/object', static function (string $chunk) use (&$received, $cancelled): void {
                    $received += strlen($chunk);
                    throw $cancelled;
                });
                $this->fail('Expected downstream cancellation to propagate');
            } catch (\RuntimeException $error) {
                $this->assertSame($cancelled, $error);
            }
            $this->assertGreaterThan(0, $received);
            $this->assertLessThanOrEqual(65536, $received);
            $this->assertSame('1', file_get_contents($marker . '.requests'));
        });
    }

    /** @param callable(Device, string): void $test */
    private function serve(callable $test): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        fclose($listener);
        $marker = tempnam(sys_get_temp_dir(), 's3-stream-');
        $this->assertIsString($marker);
        unlink($marker);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/s3-stream-server.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, env_vars: [...getenv(), 'S3_STREAM_MARKER' => $marker]);
        $this->assertIsResource($process);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $socket = @stream_socket_client('tcp://' . $address, timeout: 0.05);
                if (is_resource($socket)) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($ready);
            $device = new Telemetry(new TestTelemetry(), new S3('', 'key', 'secret', 'http://' . $address, 'us-east-1'));
            $test($device, $marker);
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            foreach ([$marker, $marker . '.requests'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
