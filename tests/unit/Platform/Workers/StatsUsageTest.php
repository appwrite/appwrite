<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\ProjectContext;
use Appwrite\Platform\Workers\StatsUsage;
use Appwrite\Usage\Connection;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Tests\Unit\Usage\ClickHouseServer;
use Utopia\Queue\Message;
use Utopia\Usage\Adapter\ClickHouse;
use Utopia\Usage\Usage;

use function Swoole\Coroutine\run;

/**
 * Drives the worker's action() against the real usage ClickHouse adapter, with
 * only the HTTP transport standing in for the server.
 */
#[RequiresPhpExtension('swoole')]
final class StatsUsageTest extends TestCase
{
    public function testFailedInsertKeepsTheEventsAndRefusesMessagesUntilTheyAreWritten(): void
    {
        $server = new ClickHouseServer();
        $server->down = true;
        $connection = Connection::fromUsage($this->usage($server));
        $worker = $this->worker();
        $refused = false;

        run(function () use ($worker, $connection, $server, &$refused): void {
            $worker->action($this->message(5), $this->project(), $connection);
            Coroutine::sleep(0.1);

            try {
                $worker->action($this->message(7), $this->project(), $connection);
            } catch (\RuntimeException) {
                $refused = true;
            }

            $server->down = false;
            Coroutine::sleep(0.3);

            $worker->action($this->message(7), $this->project(), $connection);
        });

        $this->assertSame([5, 7], $this->requests($server), 'The buffered events are written once the server is back, and the refused message once, on its redelivery');
        $this->assertTrue($refused, 'A message arriving while inserts fail must be handed back to the queue, not acknowledged');
    }

    public function testInsertsAfterASuccessfulFlushAreNotRefused(): void
    {
        $server = new ClickHouseServer();
        $connection = Connection::fromUsage($this->usage($server));
        $worker = $this->worker();

        run(function () use ($worker, $connection): void {
            $worker->action($this->message(5), $this->project(), $connection);
            Coroutine::sleep(0.1);
            $worker->action($this->message(7), $this->project(), $connection);
        });

        $this->assertSame([5, 7], $this->requests($server));
    }

    private function worker(): StatsUsage
    {
        return new class () extends StatsUsage {
            protected const int FLUSH_DELAY_MS = 20;

            protected const int RETRY_DELAY_MS = 100;
        };
    }

    private function usage(ClickHouseServer $server): Usage
    {
        return new Usage(new ClickHouse(
            host: 'clickhouse',
            client: $server,
            namespace: 'projects',
            database: 'appwrite',
            sharedTables: true,
        ));
    }

    private function project(): ProjectContext
    {
        return new ProjectContext(id: 'project-a', sequence: '123');
    }

    private function message(int $requests): Message
    {
        return new Message([
            'pid' => \uniqid(),
            'queue' => 'v1-stats-usage',
            'timestamp' => \time(),
            'payload' => [
                'project' => ['$id' => 'project-a', '$sequence' => '123'],
                'metrics' => [
                    ['key' => METRIC_NETWORK_REQUESTS, 'value' => $requests],
                ],
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function requests(ClickHouseServer $server): array
    {
        $values = [];
        foreach ($server->rows as $row) {
            if (($row['metric'] ?? null) === METRIC_NETWORK_REQUESTS) {
                $values[] = (int) $row['value'];
            }
        }

        return $values;
    }
}
