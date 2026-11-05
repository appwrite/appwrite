<?php

namespace Utopia\Orchestration\Tests\E2E;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;

abstract class Base extends TestCase
{
    abstract protected static function getOrchestration(): Orchestration;

    abstract protected static function getAdapterName(): string;

    /**
     * @var string
     */
    public static $containerID;

    /**
     * Bind-mounted into the containers the tests start: the e2e tier runs on
     * the host, so this path is the one the Docker daemon sees.
     */
    protected static function resources(): string
    {
        return __DIR__.'/Resources';
    }

    /**
     * The daemon's speed varies with whatever else the host runs, so wait for
     * a condition instead of sleeping a fixed time.
     */
    protected static function waitUntil(callable $condition, int $seconds = 30): bool
    {
        $deadline = \time() + $seconds;
        while (! $condition()) {
            if (\time() >= $deadline) {
                return false;
            }
            \usleep(250_000);
        }

        return true;
    }

    public function setUp(): void
    {
        \exec('rm -rf '.\escapeshellarg(self::resources().'/screens')); // cleanup

        \exec('cd '.\escapeshellarg(self::resources()).' && tar -zcf ./php.tar.gz php');
        \exec('cd '.\escapeshellarg(self::resources()).' && tar -zcf ./timeout.tar.gz timeout');
    }

    public function tearDown(): void
    {
        \exec('rm -rf '.\escapeshellarg(self::resources().'/screens')); // cleanup
    }

    public function testPullImage(): void
    {
        /**
         * Test for Success
         */
        $response = static::getOrchestration()->pull('appwrite/runtime-for-php:8.0');

        $this->assertSame(true, $response);

        // Used later for CPU usage test
        $response = static::getOrchestration()->pull('containerstack/alpine-stress:latest');

        $this->assertSame(true, $response);

        /**
         * Test for Failure
         */
        $response = static::getOrchestration()->pull('appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP'); // Pull non-existent Container
        $this->assertSame(false, $response);
    }

    #[Depends('testPullImage')]
    public function testCreateContainer(): void
    {
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainer',
            [
                'sh',
                '-c',
                'cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null',
            ],
            '',
            '/usr/local/src/',
            [
                self::resources().':/test:rw',
            ],
            [],
            self::resources()
        );

        $this->assertNotEmpty($response);

        // "Always" Restart policy test
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerWithRestart',
            [
                'sh',
                '-c',
                'echo "Custom start" && sleep 1 && exit 0',
            ],
            '',
            '/usr/local/src/',
            [
                self::resources().':/test:rw',
            ],
            [],
            self::resources(),
            restart: DockerAPI::RESTART_ALWAYS
        );

        $this->assertNotEmpty($response);

        sleep(10); // Docker restart can take quite long to restart. This is safety to prevent flaky tests

        $output = [];
        \exec('docker logs '.$response, $output);
        $output = \implode("\n", $output);
        $occurances = \substr_count($output, 'Custom start');
        $this->assertGreaterThanOrEqual(2, $occurances); // 2 logs mean it restarted at least once

        $response = static::getOrchestration()->remove('TestContainerWithRestart', true);
        $this->assertSame(true, $response);

        // "No" Restart policy test
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerWithoutRestart',
            [
                'sh',
                '-c',
                'echo "Custom start" && sleep 1 && exit 0',
            ],
            '',
            '/usr/local/src/',
            [
                self::resources().':/test:rw',
            ],
            [],
            self::resources(),
            restart: DockerAPI::RESTART_NO
        );

        $this->assertNotEmpty($response);

        sleep(7);

        $output = [];
        \exec('docker logs '.$response, $output);
        $output = \implode("\n", $output);
        $occurances = \substr_count($output, 'Custom start');
        $this->assertSame(1, $occurances);

        $response = static::getOrchestration()->remove('TestContainerWithoutRestart', true);
        $this->assertSame(true, $response);

        /**
         * Test for Failure
         */
        $this->expectException(\Exception::class);

        $response = static::getOrchestration()->run(
            'appwrite/txdytmheckcuz5b4plitxl1ykhzxdh', // Non-Existent Image
            'TestContainer',
            [
                'sh',
                '-c',
                'cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null',
            ],
            '',
            '/usr/local/src/',
            [],
            [],
            self::resources(),
        );

        /**
         * Test for Failure
         */
        $this->expectException(\Exception::class);

        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerBadBuild',
            [
                'sh',
                '-c',
                'cp /tmp/doesnotexist.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null',
            ],
            '',
            '/usr/local/src/',
            [],
            [],
            self::resources(),
        );
    }

    // Network Tests

    #[Depends('testCreateContainer')]
    public function testCreateNetwork(): void
    {
        $response = static::getOrchestration()->createNetwork('TestNetwork');

        $this->assertSame(true, $response);
    }

    #[Depends('testCreateNetwork')]
    public function testListNetworks(): void
    {
        $response = static::getOrchestration()->listNetworks();

        $foundNetwork = false;

        foreach ($response as $value) {
            if ($value->getName() == 'TestNetwork') {
                $foundNetwork = true;
            }
        }

        $this->assertSame(true, $foundNetwork);
    }

    #[Depends('testCreateNetwork')]
    public function testNetworkConnect(): void
    {
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerRM',
            [
                'sh',
                '-c',
                'echo Hello World!',
            ],
            '',
            '/usr/local/src/',
            [],
            [
                'teasdsa' => '',
            ],
            self::resources(),
            [
                'test2' => 'Hello World!',
            ],
            '',
            true,
            'TestNetwork'
        );

        $this->assertNotEmpty($response);

        sleep(1); // wait for container

        $response = static::getOrchestration()->networkConnect('TestContainer', 'TestNetwork');

        $this->assertSame(true, $response);
    }

    #[Depends('testNetworkConnect')]
    public function testNetworkDisconnect(): void
    {
        $response = static::getOrchestration()->networkDisconnect('TestContainer', 'TestNetwork', true);

        $this->assertSame(true, $response);
    }

    #[Depends('testNetworkDisconnect')]
    public function testRemoveNetwork(): void
    {
        $response = static::getOrchestration()->removeNetwork('TestNetwork');

        $this->assertSame(true, $response);
    }

    #[Depends('testCreateContainer')]
    public function testExecContainer(): void
    {
        /**
         * Test for Failure
         */
        $output = '';

        $threwException = false;
        try {
            static::getOrchestration()->execute(
                '60clotVWpufbEpy33zJLcoYHrUTqWaD1FV0FZWsw', // Non-Existent Container
                [
                    'php',
                    'index.php',
                ],
                $output
            );
        } catch (\Exception) {
            $threwException = true;
        }
        $this->assertTrue($threwException);

        /**
         * Test for Failure
         */
        $output = '';

        $threwException = false;
        try {
            static::getOrchestration()->execute(
                'TestContainer',
                [
                    'php',
                    'doesnotexist.php', // Non-Existent File
                ],
                $output,
                [
                    'test' => 'testEnviromentVariable',
                ],
                1
            );
        } catch (\Exception) {
            $threwException = true;
        }
        $this->assertTrue($threwException);

        /**
         * Test for Success
         */
        $output = '';

        static::getOrchestration()->execute(
            'TestContainer',
            [
                'php',
                'index.php',
            ],
            $output,
            [
                'test' => 'testEnviromentVariable',
            ],
        );

        $this->assertSame('Hello World! testEnviromentVariable', $output);

        /**
         * Test for Success
         */
        $output = '';

        static::getOrchestration()->execute(
            'TestContainer',
            [
                'sh',
                'logs.sh',
            ],
            $output
        );

        $length = 0;
        $length += 1024 * 1024 * 5; // 5MB
        $length += 5; // "start"
        $length += 3; // "end"

        $this->assertSame($length, \strlen($output));
        $this->assertStringStartsWith('START', $output);
        $this->assertStringEndsWith('END', $output);
    }

    #[Depends('testExecContainer')]
    public function testCheckVolume(): void
    {
        $output = '';

        static::getOrchestration()->execute(
            'TestContainer',
            [
                'cat',
                '/test/testfile.txt',
            ],
            $output
        );

        $this->assertSame('Lorem ipsum dolor sit amet, consectetur adipiscing elit. Cras dapibus turpis mauris, ac consectetur odio varius ullamcorper.', $output);
    }

    #[Depends('testExecContainer')]
    public function testTimeoutContainer(): void
    {
        // Create container
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerTimeout',
            [
                'sh',
                '-c',
                'cp /tmp/timeout.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null',
            ],
            '',
            '/usr/local/src/',
            [],
            [
                'teasdsa' => '',
            ],
            self::resources(),
            [
                'test2' => 'Hello World!',
            ]
        );

        $this->assertNotEmpty($response);

        self::$containerID = $response;

        /**
         * Test for Failure
         */
        $output = '';
        $threwException = false;
        try {
            $response = static::getOrchestration()->execute(
                'TestContainerTimeout',
                [
                    'php',
                    'index.php',
                ],
                $output,
                [],
                1
            );
        } catch (\Exception) {
            $threwException = true;
        }
        $this->assertTrue($threwException);

        /**
         * Test for Success
         */
        $output = '';

        $response = static::getOrchestration()->execute(
            'TestContainerTimeout',
            [
                'php',
                'index.php',
            ],
            $output,
            [],
            10
        );

        $this->assertSame(true, $response);

        /**
         * Test for Success
         */
        $output = '';

        $response = static::getOrchestration()->execute(
            'TestContainerTimeout',
            [
                'sh',
                '-c',
                'echo -n Hello World!', // -n prevents from adding linebreak afterwards
            ],
            $output,
            [],
            10
        );

        $this->assertSame('Hello World!', $output);
        $this->assertSame(true, $response);
    }

    #[Depends('testCreateContainer')]
    public function testListContainers(): void
    {
        $response = static::getOrchestration()->list();

        $foundContainer = false;

        foreach ($response as $value) {
            if ($value->getName() == 'TestContainer') {
                $foundContainer = true;
            }
        }

        $this->assertSame(true, $foundContainer);
    }

    #[Depends('testCreateContainer')]
    public function testListFilters(): void
    {
        $response = $this->getOrchestration()->list(['id' => self::$containerID]);

        $this->assertSame(self::$containerID, $response[0]->getId());
    }

    #[Depends('testPullImage')]
    public function testPreservesLabelValuesWithSingleQuotes(): void
    {
        $containerName = 'TestContainerLabelQuote';
        $labelValue = "O'Brien";

        $containerId = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            $containerName,
            [
                'sh',
                '-c',
                'tail -f /dev/null',
            ],
            labels: ['author' => $labelValue],
        );

        $this->assertNotEmpty($containerId);

        $containers = static::getOrchestration()->list(['id' => $containerId]);

        $this->assertCount(1, $containers);
        $this->assertSame($containerId, $containers[0]->getId());
        $this->assertSame($labelValue, $containers[0]->getLabels()['author']);

        $response = static::getOrchestration()->remove($containerName, true);
        $this->assertSame(true, $response);
    }

    #[Depends('testExecContainer')]
    public function testRemoveContainer(): void
    {
        /**
         * Test for Success
         */
        $response = static::getOrchestration()->remove('TestContainer', true);

        $this->assertSame(true, $response);

        $response = static::getOrchestration()->remove('TestContainerTimeout', true);
        $this->assertSame(true, $response);

        /**
         * Test for Failure
         */
        $this->expectException(\Exception::class);

        $response = static::getOrchestration()->remove('TestContainer', true);
    }

    public function testRunRemove(): void
    {
        /**
         * Test for success
         */
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerRM',
            [
                'sh',
                '-c',
                'echo Hello World!',
            ],
            '',
            '/usr/local/src/',
            [],
            [
                'teasdsa' => '',
            ],
            self::resources(),
            [
                'test2' => 'Hello World!',
            ],
            '',
            true
        );

        $this->assertNotEmpty($response);

        // Check if container exists
        $removed = self::waitUntil(fn (): bool => static::getOrchestration()->list(['id' => $response]) === []);

        $this->assertTrue($removed, 'TestContainerRM still exists after exiting');
    }

    #[Depends('testPullImage')]
    public function testUsageStats(): void
    {
        /**
         * Test for Success
         */
        // The e2e tier shares the host's Docker daemon, so count against
        // whatever was already running before this test started.
        $running = \count(static::getOrchestration()->getStats());

        // This allows CPU-heavy load check
        static::getOrchestration()->setCpus(1);

        $containerId1 = static::getOrchestration()->run(
            'containerstack/alpine-stress',  // https://github.com/containerstack/alpine-stress
            'UsageStats1',
            [
                'sh',
                '-c',
                'apk update && apk add screen && tail -f /dev/null',
            ],
            workdir: '/usr/local/src/',
            mountFolder: self::resources(),
            labels: ['utopia-container-type' => 'stats']
        );

        $this->assertNotEmpty($containerId1);

        $containerId2 = static::getOrchestration()->run(
            'containerstack/alpine-stress',
            'UsageStats2',
            [
                'sh',
                '-c',
                'apk update && apk add screen && tail -f /dev/null',
            ],
            workdir: '/usr/local/src/',
            mountFolder: self::resources(),
        );

        $this->assertNotEmpty($containerId2);

        // Both containers install screen on start
        foreach ([$containerId1, $containerId2] as $containerId) {
            $installed = self::waitUntil(function () use ($containerId): bool {
                $output = '';
                try {
                    // Bound each probe too: execute() waits forever by default, which
                    // would keep a stalled daemon from ever reaching the deadline.
                    static::getOrchestration()->execute($containerId, ['which', 'screen'], $output, timeout: 10);
                } catch (\Exception) {
                    return false;
                }

                return true;
            }, 120);

            $this->assertTrue($installed, "screen is not installed in {$containerId}");
        }

        // This allows CPU-heavy load check
        $output = '';
        static::getOrchestration()->execute($containerId1, ['screen', '-d', '-m', 'stress', '--cpu', '1', '--timeout', '5'], $output); // Run in screen so it's background task
        static::getOrchestration()->execute($containerId2, ['screen', '-d', '-m', 'stress', '--cpu', '1', '--timeout', '5'], $output);

        // Set CPU stress-test start
        \sleep(1);

        // Fetch stats, should include high CPU usage
        $stats = static::getOrchestration()->getStats();

        $this->assertCount($running + 2, $stats);

        $this->assertNotEmpty($stats[0]->getContainerId());
        $this->assertSame(64, \strlen($stats[0]->getContainerId()));

        $this->assertSame('UsageStats2', $stats[0]->getContainerName());

        $this->assertGreaterThanOrEqual(0, $stats[0]->getCpuUsage());
        $this->assertLessThanOrEqual(2, $stats[0]->getCpuUsage()); // Sometimes it gives like 102% usage

        $this->assertGreaterThanOrEqual(0, $stats[0]->getMemoryUsage());
        $this->assertLessThanOrEqual(1, $stats[0]->getMemoryUsage());

        $this->assertIsNumeric($stats[0]->getDiskIO()['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getDiskIO()['in']);
        $this->assertIsNumeric($stats[0]->getDiskIO()['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getDiskIO()['out']);

        $this->assertIsNumeric($stats[0]->getMemoryIO()['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getMemoryIO()['in']);
        $this->assertIsNumeric($stats[0]->getMemoryIO()['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getMemoryIO()['out']);

        $this->assertIsNumeric($stats[0]->getNetworkIO()['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getNetworkIO()['in']);
        $this->assertIsNumeric($stats[0]->getNetworkIO()['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getNetworkIO()['out']);

        $stats1 = static::getOrchestration()->getStats($containerId1);
        $stats2 = static::getOrchestration()->getStats($containerId2);

        $statsName1 = static::getOrchestration()->getStats('UsageStats1');
        $statsName2 = static::getOrchestration()->getStats('UsageStats2');

        $this->assertSame($statsName1[0]->getContainerId(), $stats1[0]->getContainerId());
        $this->assertSame($statsName1[0]->getContainerName(), $stats1[0]->getContainerName());
        $this->assertSame($statsName2[0]->getContainerName(), $stats2[0]->getContainerName());

        $this->assertSame($stats[1]->getContainerId(), $stats1[0]->getContainerId());
        $this->assertSame($stats[1]->getContainerName(), $stats1[0]->getContainerName());
        $this->assertSame($stats[0]->getContainerId(), $stats2[0]->getContainerId());
        $this->assertSame($stats[0]->getContainerName(), $stats2[0]->getContainerName());

        $this->assertGreaterThanOrEqual(0, $stats[0]->getCpuUsage());
        $this->assertGreaterThanOrEqual(0, $stats[1]->getCpuUsage());

        $statsFiltered = static::getOrchestration()->getStats(filters: ['label' => 'utopia-container-type=stats']);
        $this->assertCount(1, $statsFiltered);
        $this->assertSame($containerId1, $statsFiltered[0]->getContainerId());

        $statsFiltered = static::getOrchestration()->getStats(filters: ['label' => 'utopia-container-type=non-existing-type']);
        $this->assertCount(0, $statsFiltered);

        $response = static::getOrchestration()->remove('UsageStats1', true);

        $this->assertSame(true, $response);

        $response = static::getOrchestration()->remove('UsageStats2', true);

        $this->assertSame(true, $response);

        /**
         * Test for Failure
         */
        $stats = static::getOrchestration()->getStats('IDontExist');
        $this->assertCount(0, $stats);
    }

    public function testNetworkExists(): void
    {
        $networkName = 'test_network_'.uniqid();

        // Test non-existent network
        $this->assertFalse(static::getOrchestration()->networkExists($networkName));

        // Create network and test it exists
        $response = static::getOrchestration()->createNetwork($networkName);
        $this->assertTrue($response);
        $this->assertTrue(static::getOrchestration()->networkExists($networkName));

        // Remove network
        $response = static::getOrchestration()->removeNetwork($networkName);
        $this->assertTrue($response);

        // Test removed network
        $this->assertFalse(static::getOrchestration()->networkExists($networkName));
    }
}
