<?php

namespace Utopia\Tests;

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

    public function setUp(): void
    {
        \exec('rm -rf /usr/src/code/tests/Orchestration/Resources/screens'); // cleanup

        \exec('sh -c "cd /usr/src/code/tests/Orchestration/Resources && tar -zcf ./php.tar.gz php"');
        \exec('sh -c "cd /usr/src/code/tests/Orchestration/Resources && tar -zcf ./timeout.tar.gz timeout"');
    }

    public function tearDown(): void
    {
        \exec('rm -rf /usr/src/code/tests/Orchestration/Resources/screens'); // cleanup
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

    /**
     * @depends testPullImage
     */
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
                \getenv('HOST_DIR').'/tests/Orchestration/Resources:/test:rw',
            ],
            [],
            \getenv('HOST_DIR').'/tests/Orchestration/Resources'
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
                \getenv('HOST_DIR').'/tests/Orchestration/Resources:/test:rw',
            ],
            [],
            \getenv('HOST_DIR').'/tests/Orchestration/Resources',
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
                \getenv('HOST_DIR').'/tests/Orchestration/Resources:/test:rw',
            ],
            [],
            \getenv('HOST_DIR').'/tests/Orchestration/Resources',
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
            \getenv('HOST_DIR').'/tests/Orchestration/Resources',
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
            \getenv('HOST_DIR').'/tests/Orchestration/Resources',
        );
    }

    // Network Tests

    /**
     * @depends testCreateContainer
     */
    public function testCreateNetwork(): void
    {
        $response = static::getOrchestration()->createNetwork('TestNetwork');

        $this->assertSame(true, $response);
    }

    /**
     * @depends testCreateNetwork
     */
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

    /**
     * @depends testCreateNetwork
     */
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
            \getenv('HOST_DIR').'/tests/Orchestration/Resources',
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

    /**
     * @depends testNetworkConnect
     */
    public function testNetworkDisconnect(): void
    {
        $response = static::getOrchestration()->networkDisconnect('TestContainer', 'TestNetwork', true);

        $this->assertSame(true, $response);
    }

    /**
     * @depends testNetworkDisconnect
     */
    public function testRemoveNetwork(): void
    {
        $response = static::getOrchestration()->removeNetwork('TestNetwork');

        $this->assertSame(true, $response);
    }

    /**
     * @depends testCreateContainer
     */
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
        } catch (\Exception $err) {
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
        } catch (\Exception $err) {
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

    /**
     * @depends testExecContainer
     */
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

    /**
     * @depends testExecContainer
     */
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
            \getenv('HOST_DIR').'/tests/Orchestration/Resources',
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
        } catch (\Exception $err) {
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

    /**
     * @depends testCreateContainer
     */
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

    /**
     * @depends testCreateContainer
     */
    public function testListFilters(): void
    {
        $response = $this->getOrchestration()->list(['id' => self::$containerID]);

        $this->assertSame(self::$containerID, $response[0]->getId());
    }

    /**
     * @depends testPullImage
     */
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

    /**
     * @depends testExecContainer
     */
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

    public function testParseCLICommand(): void
    {
        /**
         * Test for success
         */
        $test = static::getOrchestration()->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'");

        $this->assertSame([
            'sh',
            '-c',
            "'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'",
        ], $test);

        $test = static::getOrchestration()->parseCommandString('sudo apt-get update');

        $this->assertSame([
            'sudo',
            'apt-get',
            'update',
        ], $test);

        $test = static::getOrchestration()->parseCommandString('test');

        $this->assertSame([
            'test',
        ], $test);

        /**
         * Test for failure
         */
        $this->expectException(\Exception::class);

        $test = static::getOrchestration()->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null");
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
            \getenv('HOST_DIR').'/tests/Orchestration/Resources',
            [
                'test2' => 'Hello World!',
            ],
            '',
            true
        );

        $this->assertNotEmpty($response);

        sleep(1);

        // Check if container exists
        $statusResponse = static::getOrchestration()->list(['id' => $response]);

        $this->assertSame(0, count($statusResponse));
    }

    /**
     * @depends testPullImage
     */
    public function testUsageStats(): void
    {
        /**
         * Test for Success
         */
        $stats = static::getOrchestration()->getStats();
        // 1 expected due to container running tests
        $this->assertCount(1, $stats, 'Container(s) still running: '.\json_encode($stats, JSON_PRETTY_PRINT));

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
            mountFolder: \getenv('HOST_DIR').'/tests/Orchestration/Resources',
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
            mountFolder: \getenv('HOST_DIR').'/tests/Orchestration/Resources',
        );

        $this->assertNotEmpty($containerId2);
        sleep(2);

        // This allows CPU-heavy load check
        $output = '';
        static::getOrchestration()->execute($containerId1, ['screen', '-d', '-m', 'stress', '--cpu', '1', '--timeout', '5'], $output); // Run in screen so it's background task
        static::getOrchestration()->execute($containerId2, ['screen', '-d', '-m', 'stress', '--cpu', '1', '--timeout', '5'], $output);

        // Set CPU stress-test start
        \sleep(1);

        // Fetch stats, should include high CPU usage
        $stats = static::getOrchestration()->getStats();

        $this->assertCount(2 + 1, $stats); // +1 due to container running tests

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
