<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Functions;

use OpenRuntimes\Orchestrator\Exception\TimeoutException;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;

final class JobsTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string|false $host;

    private string|false $timeout;

    protected function setUp(): void
    {
        $this->host = \getenv('_APP_JOBS_HOST');
        $this->timeout = \getenv('_APP_COMPUTE_BUILD_TIMEOUT');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            \proc_terminate($this->server);
            foreach ($this->pipes as $pipe) {
                \fclose($pipe);
            }
            \proc_close($this->server);
        }

        \putenv($this->host === false ? '_APP_JOBS_HOST' : '_APP_JOBS_HOST=' . $this->host);
        \putenv($this->timeout === false
            ? '_APP_COMPUTE_BUILD_TIMEOUT'
            : '_APP_COMPUTE_BUILD_TIMEOUT=' . $this->timeout);
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testJobsUsesConfiguredBuildTimeoutForSubmission(): void
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);
        $address = \stream_socket_get_name($socket, false);
        $this->assertIsString($address);
        \fclose($socket);

        $port = (int) \substr((string) \strrchr($address, ':'), 1);
        $this->server = \proc_open([
            PHP_BINARY,
            '-S',
            "127.0.0.1:{$port}",
            \dirname(__DIR__, 3) . '/resources/jobs.php',
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $this->pipes);
        $this->assertIsResource($this->server);

        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @\fsockopen('127.0.0.1', $port, timeout: 0.01);
            if (\is_resource($connection)) {
                \fclose($connection);
                $ready = true;
                break;
            }

            \usleep(10_000);
        }
        $this->assertTrue($ready, 'The delayed jobs test server did not start.');

        \putenv("_APP_JOBS_HOST=http://127.0.0.1:{$port}");
        \putenv('_APP_COMPUTE_BUILD_TIMEOUT=0.05');

        global $container;
        $this->assertInstanceOf(Container::class, $container);
        $jobs = $container->get('jobs');
        $this->assertInstanceOf(Jobs::class, $jobs);

        $this->expectException(TimeoutException::class);
        $jobs->create('test', 'openruntimes/test', 'true');
    }
}
