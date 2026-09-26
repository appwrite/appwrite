<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;

/**
 * Real end-to-end coverage for the KEDA ScaledJob approach: messages are pushed
 * onto the ordinary Redis queue, KEDA notices the depth and spawns worker Jobs,
 * and each Job drains the queue with the normal Redis broker. No payload is ever
 * carried on a Kubernetes object.
 *
 * Provisioned by tests/keda-e2e.sh (kind + KEDA + Redis + the ScaledJob). Skips
 * unless that harness marks the environment ready via KEDA_E2E=true.
 */
final class KedaTest extends TestCase
{
    private const string NAMESPACE = 'utopia-queue-keda';
    private const string QUEUE_KEY = 'utopia-queue.queue.keda';
    private const string FAILED_KEY = 'utopia-queue.failed.keda';
    private const int TIMEOUT = 180;

    protected function setUp(): void
    {
        if (getenv('KEDA_E2E') !== 'true') {
            $this->markTestSkipped('KEDA e2e requires the kind + KEDA harness (KEDA_E2E=true).');
        }
    }

    private function redis(string ...$args): string
    {
        $command = 'kubectl exec -n ' . self::NAMESPACE . ' deploy/redis -- redis-cli '
            . implode(' ', array_map(escapeshellarg(...), $args)) . ' 2>/dev/null';

        return trim((string) shell_exec($command));
    }

    /** @phpstan-impure reads live Redis state, so the value changes between calls */
    private function queueLength(): int
    {
        return (int) $this->redis('LLEN', self::QUEUE_KEY);
    }

    /** @phpstan-impure reads live Redis state, so the value changes between calls */
    private function failedLength(): int
    {
        return (int) $this->redis('LLEN', self::FAILED_KEY);
    }

    private function jobCount(): int
    {
        $out = shell_exec('kubectl get jobs -n ' . self::NAMESPACE . ' --no-headers 2>/dev/null');
        if ($out === null || trim($out) === '') {
            return 0;
        }

        return \count(explode("\n", trim($out)));
    }

    private function enqueue(array $payload): void
    {
        // Mirrors Redis::enqueue()'s envelope. The in-cluster Redis isn't exposed
        // to the host, so we push via kubectl rather than the broker directly;
        // keep this in sync if the envelope shape changes.
        $message = json_encode([
            'pid' => uniqid(more_entropy: true),
            'queue' => 'keda',
            'timestamp' => time(),
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        $this->redis('LPUSH', self::QUEUE_KEY, $message);
    }

    public function testKedaSpawnsJobsThatDrainTheQueue(): void
    {
        $payloads = [
            ['type' => 'test_string', 'value' => 'lorem ipsum'],
            ['type' => 'test_number', 'value' => 123],
            ['type' => 'test_assoc', 'value' => ['string' => 'ipsum', 'number' => 123, 'bool' => true, 'null' => null]],
        ];

        foreach ($payloads as $payload) {
            $this->enqueue($payload);
        }

        $this->assertSame(\count($payloads), $this->queueLength(), 'messages should be queued in Redis');

        $deadline = time() + self::TIMEOUT;
        while (time() < $deadline && $this->queueLength() > 0) {
            sleep(3);
        }

        $this->assertSame(0, $this->queueLength(), 'KEDA-spawned workers should drain the queue');
        // receive() pops from the main list before handling, so a drained main
        // queue alone doesn't prove success — assert nothing landed in .failed.*.
        $this->assertSame(0, $this->failedLength(), 'no messages should have failed');
        $this->assertGreaterThanOrEqual(1, $this->jobCount(), 'KEDA should have spawned at least one worker Job');
    }
}
