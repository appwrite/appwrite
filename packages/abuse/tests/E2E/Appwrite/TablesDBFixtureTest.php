<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests\E2E\Appwrite;

use Appwrite\AppwriteException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The owned HTTP endpoint exercises the real SDK and fixture lifecycle, not the Appwrite backend.
 *
 * @phpstan-type State array{databases: array<string, array<string, bool>>, deletes: list<string>, createFailure: int, setupFailure: int, cleanupFailure: int}
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TablesDBFixtureTest extends TestCase
{
    private string $state = '';
    private string $log = '';

    /** @var resource|null */
    private $process = null;

    /** @var array<string, string|false> */
    private array $environment = [];

    #[Override]
    protected function setUp(): void
    {
        try {
            $this->start();
        } catch (Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    private function start(): void
    {
        require_once __DIR__ . '/TablesDBTest.php';

        $this->state = tempnam(sys_get_temp_dir(), 'abuse-state-') ?: throw new RuntimeException('Cannot create fixture state');
        $this->log = tempnam(sys_get_temp_dir(), 'abuse-http-') ?: throw new RuntimeException('Cannot create fixture log');
        $this->write(['databases' => ['foreign' => []], 'deletes' => [], 'createFailure' => 0, 'setupFailure' => 0, 'cleanupFailure' => 0]);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot reserve a fixture address');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->assertIsString($address);
        foreach (['UTOPIA_ABUSE_FIXTURE' => $this->state, 'APPWRITE_ENDPOINT' => 'http://' . $address . '/v1', 'APPWRITE_PROJECT_ID' => 'fixture', 'APPWRITE_API_KEY' => 'fixture'] as $key => $value) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        $process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../../resources/appwrite.php'], [0 => ['pipe', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']], $pipes);
        if ($process === false) {
            throw new RuntimeException('Cannot start the fixture endpoint');
        }
        $this->process = $process;
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $deadline = microtime(true) + 10;
        do {
            $connection = @stream_socket_client('tcp://' . $address, timeout: 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Fixture endpoint did not start: ' . file_get_contents($this->log));
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            $state = $this->read();
            $state['cleanupFailure'] = 0;
            $this->write($state);
            TablesDBTest::tearDownAfterClass();
        } finally {
            $this->close();
        }
    }

    private function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        foreach ([$this->state, $this->log] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testSuccessfulFixtureDeletesOnlyItsDatabase(): void
    {
        TablesDBTest::setUpBeforeClass();
        $created = array_values(array_diff(array_keys($this->read()['databases']), ['foreign']));
        $this->assertCount(1, $created);
        TablesDBTest::tearDownAfterClass();
        $this->assertSame(['foreign' => []], $this->read()['databases']);
        $this->assertSame($created, $this->read()['deletes']);
        TablesDBTest::tearDownAfterClass();
        $this->assertSame($created, $this->read()['deletes']);
    }

    public function testSetupFailureCleansTheCreatedDatabase(): void
    {
        $state = $this->read();
        $state['setupFailure'] = 500;
        $this->write($state);
        try {
            TablesDBTest::setUpBeforeClass();
            $this->fail('Setup must fail');
        } catch (AppwriteException $failure) {
            $this->assertSame(500, $failure->getCode());
        }
        $this->assertSame(['foreign' => []], $this->read()['databases']);
        $this->assertCount(1, $this->read()['deletes']);
    }

    #[DataProvider('createFailures')]
    public function testFailedCreateDoesNotClaimOwnership(int $status): void
    {
        $state = $this->read();
        $state['createFailure'] = $status;
        $this->write($state);
        try {
            TablesDBTest::setUpBeforeClass();
            $this->fail('Create must fail');
        } catch (AppwriteException $failure) {
            $this->assertSame($status, $failure->getCode());
        }
        TablesDBTest::tearDownAfterClass();
        $this->assertSame(['foreign' => []], $this->read()['databases']);
        $this->assertSame([], $this->read()['deletes']);
    }

    /** @return array<string, array{int}> */
    public static function createFailures(): array
    {
        return ['forbidden' => [403], 'already exists' => [409]];
    }

    public function testSetupCanRunAgainAfterSuccessAndFailure(): void
    {
        TablesDBTest::setUpBeforeClass();
        TablesDBTest::tearDownAfterClass();
        $state = $this->read();
        $state['setupFailure'] = 500;
        $this->write($state);
        try {
            TablesDBTest::setUpBeforeClass();
            $this->fail('Setup must fail');
        } catch (AppwriteException $failure) {
            $this->assertSame(500, $failure->getCode());
        }
        $state = $this->read();
        $this->assertSame(['foreign' => []], $state['databases']);
        $state['setupFailure'] = 0;
        $this->write($state);
        TablesDBTest::setUpBeforeClass();
        $this->assertCount(2, $this->read()['databases']);
        TablesDBTest::tearDownAfterClass();
        $this->assertSame(['foreign' => []], $this->read()['databases']);
        $this->assertCount(3, array_unique($this->read()['deletes']));
    }

    public function testMissingOwnedDatabaseIsIdempotent(): void
    {
        TablesDBTest::setUpBeforeClass();
        $state = $this->read();
        $state['databases'] = ['foreign' => []];
        $this->write($state);
        TablesDBTest::tearDownAfterClass();
        TablesDBTest::tearDownAfterClass();
        $this->assertSame(['foreign' => []], $this->read()['databases']);
        $this->assertCount(1, $this->read()['deletes']);
    }

    public function testCleanupFailureRetainsOwnershipForRetry(): void
    {
        TablesDBTest::setUpBeforeClass();
        $state = $this->read();
        $state['cleanupFailure'] = 503;
        $this->write($state);
        try {
            TablesDBTest::tearDownAfterClass();
            $this->fail('Cleanup must fail');
        } catch (AppwriteException $failure) {
            $this->assertSame(503, $failure->getCode());
        }
        $state = $this->read();
        $this->assertCount(2, $state['databases']);
        $state['cleanupFailure'] = 0;
        $this->write($state);
        TablesDBTest::tearDownAfterClass();
        $this->assertSame(['foreign' => []], $this->read()['databases']);
        $this->assertCount(2, $this->read()['deletes']);
    }

    public function testSetupAndCleanupFailuresAreBothPreserved(): void
    {
        $state = $this->read();
        $state['setupFailure'] = 500;
        $state['cleanupFailure'] = 503;
        $this->write($state);
        try {
            TablesDBTest::setUpBeforeClass();
            $this->fail('Setup must fail');
        } catch (Throwable $failure) {
            $this->assertInstanceOf(Cleanup::class, $failure);
            $this->assertSame(500, $failure->setup->getCode());
            $this->assertSame(503, $failure->cleanup->getCode());
            $this->assertSame($failure->setup, $failure->getPrevious());
        }
        $this->assertCount(2, $this->read()['databases']);
        $this->assertCount(1, $this->read()['deletes']);
    }

    /** @return State */
    private function read(): array
    {
        $state = json_decode((string) file_get_contents($this->state), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($state);
        /** @var State $state */
        return $state;
    }

    /** @param State $state */
    private function write(array $state): void
    {
        file_put_contents($this->state, json_encode($state, JSON_THROW_ON_ERROR));
    }
}
