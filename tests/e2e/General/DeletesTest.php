<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Database\Factory;
use Appwrite\Event\Message\Delete;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use PHPUnit\Framework\AssertionFailedError;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Throwable;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Broker\Pool;
use Utopia\Queue\Queue;
use Utopia\Registry\Registry;

use function Swoole\Coroutine\run;

final class DeletesTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testDeleteExpiredTransactions(): void
    {
        /** @var Registry $register */
        global $register;

        $projectId = $this->getProject(true)['$id'];
        $queue = new Queue('deletes-test-' . ID::unique());
        $output = tmpfile();
        $this->assertIsResource($output);
        $worker = proc_open(
            ['setsid', PHP_BINARY, 'app/worker.php', 'deletes'],
            [0 => ['pipe', 'r'], 1 => $output, 2 => $output],
            $pipes,
            dirname(__DIR__, 3),
            array_merge(getenv(), [
                '_APP_DELETE_QUEUE_NAME' => $queue->name,
                '_APP_WORKERS_NUM' => '1',
                '_APP_WORKER_MAX_COROUTINES' => '1',
                '_APP_EMAIL_CERTIFICATES' => 'deletes@appwrite.test',
            ]),
        );
        $this->assertIsResource($worker);
        $pid = proc_get_status($worker)['pid'];
        fclose($pipes[0]);
        $error = null;

        try {
            run(function () use ($register, $projectId, $queue, &$error): void {
                try {
                    $factory = new Factory($register->get('pools'), new Cache(new NoCache()), new Authorization());
                    $platform = $factory->platform();
                    $platform->getAuthorization()->skip(function () use ($register, $platform, $factory, $projectId, $queue): void {
                        $project = $platform->getDocument('projects', $projectId);
                        $database = $factory->project($project);
                        $publisher = new DeletePublisher(new Pool(publisher: $register->get('pools')->get('publisher')), $queue);
                        $expired = DateTime::addSeconds(new \DateTime(), -3600);
                        $future = DateTime::addSeconds(new \DateTime(), 3600);

                        $active = $database->createDocument('transactions', new Document([
                            '$id' => ID::unique(),
                            'operations' => 1,
                            'expiresAt' => $future,
                        ]));
                        $activeLog = $database->createDocument('transactionLogs', new Document([
                            '$id' => ID::unique(),
                            'transactionInternalId' => $active->getSequence(),
                            'databaseInternalId' => 'database',
                            'collectionInternalId' => 'collection',
                            'action' => 'create',
                            'data' => ['document' => ['$id' => ID::unique()]],
                        ]));

                        /**
                         * Test for SUCCESS: queued maintenance removes expired transactions and
                         * all their logs across the configured query limit. A second job cleans
                         * new expired data while preserving the active transaction and its log.
                         */
                        foreach ([$database->getMaxQueryValues() + 1, 1] as $count) {
                            $transactions = [];
                            for ($i = 0; $i < $count; $i++) {
                                $transactions[] = new Document([
                                    '$id' => ID::unique(),
                                    'operations' => 1,
                                    'expiresAt' => $expired,
                                ]);
                            }

                            $logs = [];
                            $this->assertSame($count, $database->createDocuments('transactions', $transactions, onNext: function (Document $transaction) use (&$logs): void {
                                $logs[] = new Document([
                                    '$id' => ID::unique(),
                                    'transactionInternalId' => $transaction->getSequence(),
                                    'databaseInternalId' => 'database',
                                    'collectionInternalId' => 'collection',
                                    'action' => 'create',
                                    'data' => ['document' => ['$id' => ID::unique()]],
                                ]);
                            }));
                            $this->assertSame($count, $database->createDocuments('transactionLogs', $logs));
                            $this->assertTrue($publisher->enqueue(new Delete(
                                project: $project,
                                type: DELETE_TYPE_MAINTENANCE,
                                hourlyUsageRetentionDatetime: $expired,
                            )));

                            $this->assertEventually(function () use ($database): void {
                                $this->assertSame(1, $database->count('transactions'));
                                $this->assertSame(1, $database->count('transactionLogs'));
                            }, timeoutMs: 30_000);
                            $this->assertFalse($database->getDocument('transactions', $active->getId())->isEmpty());
                            $this->assertFalse($database->getDocument('transactionLogs', $activeLog->getId())->isEmpty());
                            $this->assertSame(0, $publisher->getSize(failed: true));
                        }
                    });
                } catch (Throwable $th) {
                    $error = $th;
                }
            });

            if ($error !== null) {
                rewind($output);
                $this->fail($error->getMessage() . "\n" . stream_get_contents($output));
            }
        } finally {
            try {
                proc_terminate($worker);
                try {
                    $this->assertEventually(function () use ($worker): void {
                        $this->assertFalse(proc_get_status($worker)['running']);
                    }, timeoutMs: 5_000, waitMs: 100);
                } catch (AssertionFailedError) {
                    // The worker owns a session, so escalation also stops its Swoole children.
                    posix_kill(-$pid, SIGKILL);
                    $this->assertEventually(function () use ($worker): void {
                        $this->assertFalse(proc_get_status($worker)['running']);
                    }, timeoutMs: 5_000, waitMs: 100);
                }
            } finally {
                if (!proc_get_status($worker)['running']) {
                    proc_close($worker);
                }
                fclose($output);
                $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, [
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => 'console',
                    'cookie' => 'a_session_console=' . $this->getRoot()['session'],
                ]);
            }
        }
    }
}
