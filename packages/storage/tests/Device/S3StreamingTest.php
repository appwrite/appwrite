<?php

declare(strict_types=1);

namespace Utopia\Storage\Tests\Device;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Exception\NotFoundException;
use Utopia\Storage\Exception\RemoteException;

final class S3StreamingTest extends TestCase
{
    public function testBytesReachSinkBeforeTheBackendFinishes(): void
    {
        $this->serve(function (S3 $device, string $marker): void {
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
        $this->serve(function (S3 $device): void {
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
        $this->serve(function (S3 $device): void {
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

    /** @param callable(S3, string): void $test */
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
            $device = new S3('', 'key', 'secret', 'http://' . $address, 'us-east-1');
            $test($device, $marker);
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            if (file_exists($marker)) {
                unlink($marker);
            }
        }
    }
}
