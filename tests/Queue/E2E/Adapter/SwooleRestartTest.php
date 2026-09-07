<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Process;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Queue;

final class SwooleRestartTest extends TestCase
{
    private mixed $process = null;

    private mixed $output = null;

    private string $buffer = '';

    private string $namespace;

    private string $log;

    private array $events = [];

    private array $children = [];

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);
            $deadline = microtime(true) + 3;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            foreach ($this->children as $pid) {
                if (Process::kill($pid, 0)) {
                    Process::kill($pid, SIGKILL);
                }
            }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, SIGKILL);
            }
            fclose($this->output);
            proc_close($this->process);
        }
        if (isset($this->log)) {
            unlink($this->log);
        }
    }

    public static function poolSizes(): \Iterator
    {
        yield 'single worker' => [1];
        yield 'three workers' => [3];
    }

    #[DataProvider('poolSizes')]
    public function testExitedWorkerIsReplacedAndProcessesMessages(int $workers): void
    {
        $this->start($workers);
        $initial = $this->waitFor('ready', $workers);
        $pids = array_column($initial, 'pid', 'worker');

        foreach (['kill', 'fatal', 'retire'] as $mode) {
            $this->events = [];
            if ($mode === 'kill') {
                $this->assertTrue(Process::kill($pids[0], SIGKILL));
            } else {
                $this->publish(0, $mode);
            }
            $replacement = $this->waitFor('ready', 1)[0];
            $this->assertSame('0', $replacement['worker']);
            $this->assertNotSame($pids[0], $replacement['pid']);
            $pids[0] = $replacement['pid'];

            for ($id = 0; $id < $workers; $id++) {
                $this->publish($id, 'probe');
            }
            $processed = $this->waitFor('processed', $workers);
            $actual = array_column($processed, 'pid', 'worker');
            ksort($actual);
            ksort($pids);
            $this->assertSame($pids, $actual, 'Replacement and surviving workers must all consume their queues');
            $this->assertTrue(proc_get_status($this->process)['running']);
        }
        $this->assertStringContainsString('Allowed memory size', (string) file_get_contents($this->log));
    }

    public function testSimultaneousWorkerExitsRestoreTheWholePool(): void
    {
        $this->start(3);
        $ready = $this->waitFor('ready', 3);
        $this->events = [];
        foreach ($ready as $worker) {
            $this->assertTrue(Process::kill($worker['pid'], SIGKILL));
        }
        $replacements = $this->waitFor('ready', 3);
        $pids = array_column($replacements, 'pid', 'worker');
        ksort($pids);
        $this->assertSame([0, 1, 2], array_keys($pids));
        $this->assertSame([], array_intersect(array_column($ready, 'pid'), array_values($pids)));
        for ($id = 0; $id < 3; $id++) {
            $this->publish($id, 'probe');
        }
        $processed = array_column($this->waitFor('processed', 3), 'pid', 'worker');
        ksort($processed);
        $this->assertSame($pids, $processed);
    }

    public static function stopSignals(): \Iterator
    {
        yield 'SIGTERM' => [SIGTERM];
        yield 'SIGINT' => [SIGINT];
    }

    #[DataProvider('stopSignals')]
    public function testShutdownDrainsJobWithoutRestartingWorkers(int $signal): void
    {
        $this->start(3);
        $ready = $this->waitFor('ready', 3);
        $this->events = [];
        $this->publish(0, 'slow');
        $this->waitFor('started', 1);
        $this->assertTrue(proc_terminate($this->process, $signal));
        $this->waitFor('exited', 1);
        $this->assertCount(1, array_filter($this->events, fn(array $e): bool => $e['event'] === 'processed'));
        $this->assertCount(3, array_filter($this->events, fn(array $e): bool => $e['event'] === 'stopped'));
        $this->assertCount(0, array_filter($this->events, fn(array $e): bool => $e['event'] === 'ready'));
        foreach ($ready as $worker) {
            $this->assertFalse(Process::kill($worker['pid'], 0), 'Supervisor must reap every child before returning');
        }
    }

    private function start(int $workers): void
    {
        $this->namespace = 'restart-' . bin2hex(random_bytes(8));
        $this->log = tempnam(sys_get_temp_dir(), 'queue-restart-');
        $this->process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=/dev/stderr', __DIR__ . '/../../servers/Swoole/restart.php', $this->namespace, (string) $workers],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->log, 'a']],
            $pipes,
        );
        $this->assertIsResource($this->process);
        $this->output = $pipes[1];
        stream_set_blocking($this->output, false);
    }

    private function publish(int $worker, string $mode): void
    {
        $broker = new Redis(new Connection('127.0.0.1', 16379), new Connection('127.0.0.1', 16379));
        $this->assertTrue($broker->publish(new Queue('worker-' . $worker, $this->namespace), ['mode' => $mode]));
    }

    private function waitFor(string $event, int $count): array
    {
        $deadline = microtime(true) + 10;
        do {
            $this->buffer .= stream_get_contents($this->output);
            while (($newline = strpos($this->buffer, "\n")) !== false) {
                $line = substr($this->buffer, 0, $newline);
                $this->buffer = substr($this->buffer, $newline + 1);
                $value = json_decode($line, true);
                if (\is_array($value) && isset($value['event'])) {
                    if ($value['event'] === 'error') {
                        $this->fail('Worker error: ' . $value['message']);
                    }
                    $this->events[] = $value;
                    if ($value['event'] === 'ready') {
                        $this->children[$value['pid']] = $value['pid'];
                    }
                }
            }
            $found = array_values(array_filter($this->events, fn(array $e): bool => $e['event'] === $event));
            if (\count($found) >= $count) {
                return $found;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for ' . $event . ': ' . json_encode($this->events) . "\n" . file_get_contents($this->log));
    }
}
