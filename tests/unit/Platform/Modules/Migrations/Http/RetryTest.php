<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations\Http;

use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Update;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Filter;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Exception\Contention;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../../../app/init.php';

final class RetryTest extends TestCase
{
    private Database $database;

    private MockPublisher $publisher;

    protected function setUp(): void
    {
        $this->database = new Database(new Memory(), new Cache(new NoCache()));
        $this->database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationRetry')
            ->setNamespace('migration_retry_' . \uniqid());
        $this->database->create();
        $this->database->createCollection(Collection::create(
            id: 'databases',
            attributes: [
                Attribute::string('migrationId', size: Database::LENGTH_KEY),
                Attribute::string('migrationAttemptId', size: Database::LENGTH_KEY),
            ],
        ));
        $this->database->createCollection(Collection::create(
            id: 'migrations',
            attributes: [
                Attribute::string('status', size: 255, required: true),
                Attribute::string('stage', size: 255, required: true),
                Attribute::string('attemptId', size: Database::LENGTH_KEY),
                Attribute::string('resourceData', size: 131_070, required: true, filters: [Filter::Json]),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));
        $this->publisher = new MockPublisher();
    }

    /**
     * @return \Iterator<string, array{bool}>
     */
    public static function schemas(): \Iterator
    {
        yield 'after V26' => [true];
        yield 'before V26' => [false];
    }

    #[DataProvider('schemas')]
    public function testRetryOfAFailedMigrationAnswersNoContentAsOnMain(bool $ready): void
    {
        $this->failedMigration();
        if (!$ready) {
            $this->database->deleteAttribute('databases', 'migrationAttemptId');
        }

        $this->retry($this->noContent(), static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback());

        $this->assertCount(1, $this->publisher->getEvents('migrations') ?? []);
    }

    public function testRetryRepeatedBeforeTheWorkerStartsIsPublishedAgainAsOnMain(): void
    {
        $this->failedMigration();
        $locks = static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback();

        $this->retry($this->noContent(), $locks);
        $this->retry($this->noContent(), $locks);

        $this->assertCount(2, $this->publisher->getEvents('migrations') ?? [], 'Main published every retry; only the latest claim is consumed');
    }

    #[DataProvider('schemas')]
    public function testAClaimedRetryStillListsAndReadsAsFailedAsOnMain(bool $ready): void
    {
        $this->failedMigration();
        if (!$ready) {
            $this->database->deleteAttribute('databases', 'migrationAttemptId');
        }

        $this->retry($this->noContent(), static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback());

        $this->assertSame('failed', $this->database->getDocument('migrations', 'migration-1')->getAttribute('status'), 'Main kept a retried migration failed until the worker started it');
        $this->assertSame(
            ['migration-1'],
            \array_map(static fn (Document $migration): string => $migration->getId(), $this->database->find('migrations', [Query::equal('status', ['failed'])])),
            'A status filter must return the retried migration as on main',
        );
        $this->assertSame([], $this->database->find('migrations', [Query::equal('status', ['pending'])]));
    }

    public function testRetryWhileAnotherRequestHoldsItsClaimAnswersNoContent(): void
    {
        $this->failedMigration();

        $this->retry($this->noContent(), static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => throw new Contention('Failed to acquire lock: ' . $key));

        $this->assertNull($this->publisher->getEvents('migrations'), 'The request holding the claim publishes the retry');
    }

    #[DataProvider('schemas')]
    public function testRetryOfAMigrationThatHasNotFailedIsRefusedWithMainsMessage(bool $ready): void
    {
        $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-1',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));
        if (!$ready) {
            $this->database->deleteAttribute('databases', 'migrationAttemptId');
        }

        try {
            $this->retry($this->createStub(Response::class), static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback());
            $this->fail('A migration that has not failed must be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_IN_PROGRESS, $error->getType());
            $this->assertSame(409, $error->getCode());
            $this->assertSame('Migration not failed yet', $error->getMessage());
        }
    }

    private function failedMigration(): void
    {
        $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-1',
            'status' => 'failed',
            'stage' => 'finished',
            'resourceData' => [],
        ]));
    }

    private function noContent(): Response
    {
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('noContent');
        $response->expects($this->never())->method('dynamic');
        $response->expects($this->never())->method('setStatusCode');

        return $response;
    }

    private function retry(Response $response, callable $locks): void
    {
        (new Update())->action(
            migrationId: 'migration-1',
            response: $response,
            dbForProject: $this->database,
            project: new Document(['$id' => 'project-1']),
            platform: [],
            publisherForMigrations: new MigrationPublisher($this->publisher, new Queue('migrations')),
            locks: $locks,
        );
    }
}
