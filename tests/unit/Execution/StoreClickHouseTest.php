<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Appwrite\Execution\Store;
use Closure;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Throwable;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleClientAdapter;
use Utopia\Client\Client;
use Utopia\Client\Pool as HttpClientPool;
use Utopia\Database\Document;
use Utopia\DSN\DSN;
use Utopia\Pools\Adapter\Swoole as SwoolePoolAdapter;
use Utopia\Pools\Pool as Connections;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\System\System;

use function Swoole\Coroutine\run;

/**
 * run() leaves every runtime hook enabled and a reactor behind, so each test gets a process of its own.
 */
#[RunTestsInSeparateProcesses]
final class StoreClickHouseTest extends TestCase
{
    private const int WRITERS = 30;

    private const int POOL_SIZE = 24;

    private const float POOL_TIMEOUT_SECONDS = 3.0;

    private const int PARTS_TO_DELAY_INSERT = 8;

    private const int PARTS_TO_THROW_INSERT = 10;

    private const int MAX_ACTIVE_PARTS = 2;

    private const int COUNT_LIMIT = 1000;

    private const string PROJECT = 'project';

    private const string TABLE = 'executions';

    private const string DATABASE_PREFIX = 'executions_test_';

    private const int DATABASE_SUFFIX_BYTES = 8;

    private const int HTTP_OK = 200;

    private ClientInterface $client;

    private RequestFactory $requests;

    private DSN $dsn;

    private string $database;

    public function testConcurrentWritesStayUnderPartLimit(): void
    {
        $this->assertWritesStayUnderPartLimit(fn (int $writer): string => self::PROJECT);
    }

    public function testConcurrentWritesAcrossProjectsStayUnderPartLimit(): void
    {
        $this->assertWritesStayUnderPartLimit(fn (int $writer): string => self::PROJECT . $writer);
    }

    /** @param Closure(int): string $projectOf */
    private function assertWritesStayUnderPartLimit(Closure $projectOf): void
    {
        $connection = System::getEnv('_APP_CONNECTIONS_DB_EXECUTIONS', System::getEnv('_APP_CONNECTIONS_DB_USAGE', ''));
        if ($connection === '') {
            $this->markTestSkipped('No execution ClickHouse connection is configured');
        }

        $this->dsn = new DSN($connection);
        $this->database = self::DATABASE_PREFIX . \bin2hex(\random_bytes(self::DATABASE_SUFFIX_BYTES));
        $this->requests = new RequestFactory();
        $this->client = new HttpClientPool(new Connections(
            new SwoolePoolAdapter(),
            'executions',
            self::POOL_SIZE,
            fn () => new Client((new SwooleClientAdapter())->withConnectionReuse()),
            timeout: self::POOL_TIMEOUT_SECONDS,
        ));
        $store = new Store($this->isolatedDsn(), $this->client);
        $projects = \array_map($projectOf, \range(0, self::WRITERS - 1));

        /** @var list<Throwable> $failures */
        $failures = [];
        /** @var array<string, int> $counts */
        $counts = [];
        $parts = 0;
        $error = null;

        run(function () use ($store, $projects, &$failures, &$counts, &$parts, &$error): void {
            try {
                $store->setup();
                $table = "`{$this->database}`.`" . self::TABLE . '`';
                $this->statement("SYSTEM STOP MERGES {$table}");
                $this->statement("ALTER TABLE {$table} MODIFY SETTING parts_to_delay_insert = " . self::PARTS_TO_DELAY_INSERT . ', parts_to_throw_insert = ' . self::PARTS_TO_THROW_INSERT);

                $group = new WaitGroup();
                foreach ($projects as $writer => $project) {
                    $group->add();
                    Coroutine::create(function () use ($store, $writer, $project, $group, &$failures): void {
                        try {
                            $store->upsert($project, new Document([
                                '$id' => "execution{$writer}",
                                'resourceType' => 'functions',
                                'resourceInternalId' => (string) ($writer + 1),
                                'resourceId' => 'function',
                                'status' => 'completed',
                                'trigger' => 'http',
                                'responseStatusCode' => self::HTTP_OK,
                            ]));
                        } catch (Throwable $failure) {
                            $failures[] = $failure;
                        } finally {
                            $group->done();
                        }
                    });
                }
                $group->wait();

                foreach (\array_unique($projects) as $project) {
                    $counts[$project] = $store->count($project, [], self::COUNT_LIMIT);
                }
                $parts = (int) \trim($this->statement(
                    'SELECT max(c) FROM (SELECT partition_id, count() AS c FROM system.parts WHERE database = {database:String} AND table = {table:String} AND active GROUP BY partition_id) FORMAT TabSeparated',
                    ['database' => $this->database, 'table' => self::TABLE],
                ));
            } catch (Throwable $thrown) {
                $error = $thrown;
            } finally {
                $this->cleanUp();
            }
        });

        if ($error instanceof Throwable) {
            throw $error;
        }

        $evidence = "\nAt most {$parts} active parts per partition, " . \array_sum($counts) . ' readable rows.';
        $this->assertSame([], \array_map(
            static fn (Throwable $failure): string => $failure->getMessage(),
            $failures,
        ), 'Concurrent execution writes failed.' . $evidence);
        $this->assertSame(\array_count_values($projects), $counts, 'Every concurrent execution write must be readable.' . $evidence);
        $this->assertLessThanOrEqual(self::MAX_ACTIVE_PARTS, $parts, 'Concurrent execution writes must share parts.' . $evidence);
    }

    private function isolatedDsn(): string
    {
        $user = $this->dsn->getUser();
        $password = $this->dsn->getPassword();
        $port = $this->dsn->getPort();
        $query = $this->dsn->getQuery();

        $credentials = $user === null ? '' : \rawurlencode($user) . ($password === null ? '' : ':' . \rawurlencode($password)) . '@';

        return $this->dsn->getScheme() . '://'
            . $credentials
            . $this->dsn->getHost()
            . ($port === null ? '' : ':' . $port)
            . '/' . $this->database
            . ($query === null ? '' : '?' . $query);
    }

    /** @param array<string, string> $parameters */
    private function statement(string $sql, array $parameters = []): string
    {
        $query = [];
        foreach ($parameters as $name => $value) {
            $query['param_' . $name] = $value;
        }

        $scheme = \strtolower($this->dsn->getParam('secure')) === 'true' ? 'https' : 'http';
        $url = "{$scheme}://{$this->dsn->getHost()}:" . ($this->dsn->getPort() ?: '8123') . '/' . ($query === [] ? '' : '?' . \http_build_query($query));
        $response = $this->client->sendRequest($this->requests->text(Method::POST, $url, $sql, [
            'X-ClickHouse-User' => $this->dsn->getUser() ?: 'default',
            'X-ClickHouse-Key' => $this->dsn->getPassword() ?? '',
        ]));

        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== self::HTTP_OK) {
            throw new RuntimeException("ClickHouse refused {$sql}: HTTP {$response->getStatusCode()}: {$body}");
        }

        return $body;
    }

    private function cleanUp(): void
    {
        $statements = [
            "SYSTEM START MERGES `{$this->database}`.`" . self::TABLE . '`',
            "DROP DATABASE IF EXISTS `{$this->database}`",
        ];
        foreach ($statements as $statement) {
            try {
                $this->statement($statement);
            } catch (Throwable) {
                // Cleanup is best-effort so it never masks the test's own outcome.
            }
        }
    }
}
