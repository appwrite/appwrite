<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

/**
 * Load test for the functions worker (PoC, not part of CI).
 *
 * Deploys a function that sleeps ~1s, fires N async executions and measures
 * the wall time until the queue drains. Run with different
 * _APP_WORKER_MAX_COROUTINES values on appwrite-worker-functions to compare
 * coroutine concurrency throughput.
 */
class FunctionsLoadTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideServer;

    private const EXECUTIONS = 30;
    private const DRAIN_TIMEOUT_SECONDS = 180;

    public function testAsyncExecutionThroughput(): void
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Load Test Sleep',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            // Must exceed worst-case queue drain time: executions waiting in the
            // queue longer than the function timeout are reported as failed.
            'timeout' => 300,
        ]);

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('sleep'),
            'activate' => true,
        ]);

        // Warm up the runtime so cold start does not skew the measurement.
        $execution = $this->createExecution($functionId, ['async' => false]);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);

        // Fire N async executions.
        $start = \microtime(true);

        for ($i = 0; $i < self::EXECUTIONS; $i++) {
            $execution = $this->createExecution($functionId, ['async' => true]);
            $this->assertEquals(202, $execution['headers']['status-code']);
        }

        $enqueued = \microtime(true) - $start;

        // Poll until all executions reach a terminal state.
        // The warmup execution is already completed, so expect N + 1.
        $expected = self::EXECUTIONS + 1;
        $completed = 0;
        $failed = 0;
        $drained = null;

        while (\microtime(true) - $start < self::DRAIN_TIMEOUT_SECONDS) {
            $completed = $this->countExecutions($functionId, 'completed');
            $failed = $this->countExecutions($functionId, 'failed');

            if ($completed + $failed >= $expected) {
                $drained = \microtime(true) - $start;
                break;
            }

            \usleep(250_000);
        }

        $this->assertNotNull($drained, "Queue did not drain in " . self::DRAIN_TIMEOUT_SECONDS . "s ({$completed} completed, {$failed} failed)");

        if ($failed > 0) {
            $samples = $this->listExecutions($functionId, [
                'queries' => [
                    Query::equal('status', ['failed'])->toString(),
                    Query::limit(3)->toString(),
                ],
            ]);

            $details = \array_map(fn ($e) => [
                'responseStatusCode' => $e['responseStatusCode'] ?? null,
                'errors' => $e['errors'] ?? null,
                'logs' => $e['logs'] ?? null,
                'duration' => $e['duration'] ?? null,
            ], $samples['body']['executions'] ?? []);

            $this->fail("{$failed} executions failed. Samples: " . \json_encode($details, JSON_PRETTY_PRINT));
        }
        $this->assertEquals($expected, $completed);

        $throughput = self::EXECUTIONS / $drained;

        \fwrite(STDERR, \sprintf(
            "\n[load] executions=%d enqueue=%.2fs drain=%.2fs throughput=%.2f exec/s\n",
            self::EXECUTIONS,
            $enqueued,
            $drained,
            $throughput,
        ));

        $this->cleanupFunction($functionId);
    }

    private function countExecutions(string $functionId, string $status): int
    {
        $executions = $this->listExecutions($functionId, [
            'queries' => [
                Query::equal('status', [$status])->toString(),
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);

        return $executions['body']['total'];
    }
}
