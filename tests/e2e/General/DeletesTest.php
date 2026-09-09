<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Database\Factory;
use Appwrite\Event\Message\Delete;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Usage;
use Appwrite\Execution\Store;
use Appwrite\Platform\Workers\Deletes;
use Executor\Executor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Throwable;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\Storage\Device\Local;

use function Swoole\Coroutine\run;

final class DeletesTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public static function transactionCounts(): \Iterator
    {
        yield 'default query limit' => [5001, 5000];
        yield 'custom query limit' => [5, 2];
    }

    #[DataProvider('transactionCounts')]
    public function testDeleteExpiredTransactions(int $count, int $maxValues): void
    {
        /** @var Registry $register */
        global $register;

        $projectId = $this->getProject(true)['$id'];
        $error = null;

        run(function () use ($register, $projectId, $count, $maxValues, &$error): void {
            try {
                $factory = new Factory($register->get('pools'), new Cache(new NoCache()), new Authorization());
                $platform = $factory->platform();
                $platform->getAuthorization()->skip(function () use ($platform, $factory, $projectId, $count, $maxValues): void {
                    $project = $platform->getDocument('projects', $projectId);
                    $database = $factory->project($project);
                    $expired = DateTime::addSeconds(new \DateTime(), -3600);
                    $future = DateTime::addSeconds(new \DateTime(), 3600);
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

                    $database->setMaxQueryValues($maxValues);
                    $device = new Local();

                    /**
                     * Test for SUCCESS: all expired logs are removed, including the last batch.
                     * Repeating maintenance preserves the active transaction and its log.
                     */
                    for ($attempt = 0; $attempt < 2; $attempt++) {
                        (new Deletes())->action(
                            new Message()->setPayload((new Delete(
                                project: $project,
                                type: DELETE_TYPE_MAINTENANCE,
                                hourlyUsageRetentionDatetime: $expired,
                            ))->toArray()),
                            $project,
                            $platform,
                            fn (): Database => $database,
                            fn (): Database => $database,
                            fn (): Database => $factory->logs($project),
                            $device,
                            $device,
                            $device,
                            $device,
                            $device,
                            $this->createStub(Provider::class),
                            $this->createStub(Executor::class),
                            $expired,
                            0,
                            $this->createStub(DeletePublisher::class),
                            $this->createStub(Usage::class),
                            $this->createStub(Bus::class),
                            $this->createStub(Store::class),
                        );

                        $this->assertSame(0, $database->count('transactions', [Query::lessThan('expiresAt', $future)]));
                        $this->assertSame(1, $database->count('transactions'));
                        $this->assertSame(1, $database->count('transactionLogs'));
                        $this->assertFalse($database->getDocument('transactions', $active->getId())->isEmpty());
                        $this->assertFalse($database->getDocument('transactionLogs', $activeLog->getId())->isEmpty());
                    }
                });
            } catch (Throwable $th) {
                $error = $th;
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }
}
