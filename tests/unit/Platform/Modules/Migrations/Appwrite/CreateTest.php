<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations\Appwrite;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Appwrite\Create;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../../../app/init.php';

final class CreateTest extends TestCase
{
    private string|false $claimEnabled;

    protected function setUp(): void
    {
        $this->claimEnabled = \getenv('_APP_MIGRATIONS_CLAIM_ENABLED');
        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=enabled');
    }

    protected function tearDown(): void
    {
        \putenv($this->claimEnabled === false
            ? '_APP_MIGRATIONS_CLAIM_ENABLED'
            : '_APP_MIGRATIONS_CLAIM_ENABLED=' . $this->claimEnabled);
    }

    public function testRefusesIncompleteOwnershipSchemaBeforePersistingAttempt(): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationCreateIncomplete')
            ->setNamespace('migration_create_incomplete_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(
            id: 'migrations',
            attributes: [
                new Attribute('attemptId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('status', ColumnType::String, size: 255, required: true),
                new Attribute('stage', ColumnType::String, size: 255, required: true),
                new Attribute('source', ColumnType::String, size: 8192, required: true),
                new Attribute('destination', ColumnType::String, size: 8192),
                new Attribute('credentials', ColumnType::String, size: 65_536, filters: ['json']),
                new Attribute('resources', ColumnType::String, size: 255, required: true, array: true),
                new Attribute('statusCounters', ColumnType::String, size: 3000, required: true, filters: ['json']),
                new Attribute('resourceData', ColumnType::String, size: 131_070, required: true, filters: ['json']),
                new Attribute('errors', ColumnType::String, size: 1_000_000, required: true, array: true),
                new Attribute('options', ColumnType::String, size: 65_536, filters: ['json']),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::delete(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));
        $publisher = new MockPublisher();

        try {
            (new Create())->action(
                resources: [],
                endpoint: 'https://example.test/v1',
                projectId: 'source-project',
                apiKey: 'source-key',
                onDuplicate: OnDuplicate::Fail->value,
                response: $this->createStub(Response::class),
                dbForProject: $database,
                project: new Document(['$id' => 'project-1']),
                platform: ['name' => 'test-platform'],
                queueForEvents: new Event($publisher),
                publisherForMigrations: new MigrationPublisher($publisher, new Queue('migrations')),
                locks: static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
            );
            $this->fail('Expected incomplete ownership schema to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_SCHEMA_NOT_READY, $error->getType());
            $this->assertSame(503, $error->getCode());
        }

        $this->assertSame([], $database->find('migrations'));
        $this->assertEmpty($publisher->getEvents('migrations'));
    }

    public function testInitialAttemptIsPersistedBeforeItIsPublished(): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationCreate')
            ->setNamespace('migration_create_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(
            id: 'databases',
            attributes: [
                new Attribute('migrationId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('migrationAttemptId', ColumnType::String, size: Database::LENGTH_KEY),
            ],
        ));
        $database->createCollection(new Collection(
            id: 'migrations',
            attributes: [
                new Attribute('attemptId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('status', ColumnType::String, size: 255, required: true),
                new Attribute('stage', ColumnType::String, size: 255, required: true),
                new Attribute('source', ColumnType::String, size: 8192, required: true),
                new Attribute('destination', ColumnType::String, size: 8192),
                new Attribute('credentials', ColumnType::String, size: 65_536, filters: ['json']),
                new Attribute('resources', ColumnType::String, size: 255, required: true, array: true),
                new Attribute('statusCounters', ColumnType::String, size: 3000, required: true, filters: ['json']),
                new Attribute('resourceData', ColumnType::String, size: 131_070, required: true, filters: ['json']),
                new Attribute('errors', ColumnType::String, size: 1_000_000, required: true, array: true),
                new Attribute('options', ColumnType::String, size: 65_536, filters: ['json']),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::delete(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));

        $publisher = new MockPublisher();
        $events = new Event($publisher);
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->once())
            ->method('setStatusCode')
            ->with(Response::STATUS_CODE_ACCEPTED)
            ->willReturnSelf();
        $response
            ->expects($this->once())
            ->method('dynamic')
            ->with($this->isInstanceOf(Document::class), Response::MODEL_MIGRATION);

        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=disabled');
        try {
            (new Create())->action(
                resources: [],
                endpoint: 'https://example.test/v1',
                projectId: 'source-project',
                apiKey: 'source-key',
                onDuplicate: OnDuplicate::Fail->value,
                response: $response,
                dbForProject: $database,
                project: new Document(['$id' => 'project-1']),
                platform: ['name' => 'test-platform'],
                queueForEvents: $events,
                publisherForMigrations: new MigrationPublisher($publisher, new Queue('migrations')),
                locks: static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
            );
            $this->fail('Expected disabled claim protocol to refuse the producer');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_CLAIM_DISABLED, $error->getType());
            $this->assertSame(503, $error->getCode());
        }
        $this->assertSame([], $database->find('migrations'));
        $this->assertEmpty($publisher->getEvents('migrations'));

        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=enabled');

        (new Create())->action(
            resources: [],
            endpoint: 'https://example.test/v1',
            projectId: 'source-project',
            apiKey: 'source-key',
            onDuplicate: OnDuplicate::Fail->value,
            response: $response,
            dbForProject: $database,
            project: new Document(['$id' => 'project-1']),
            platform: ['name' => 'test-platform'],
            queueForEvents: $events,
            publisherForMigrations: new MigrationPublisher($publisher, new Queue('migrations')),
            locks: static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
        );

        $migrations = $database->find('migrations');
        $this->assertCount(1, $migrations);
        $migration = $migrations[0];
        $attemptId = $migration->getAttribute('attemptId');
        $this->assertIsString($attemptId);
        $this->assertNotSame('', $attemptId);

        $queued = $publisher->getEvents('migrations');
        $this->assertCount(1, $queued);
        $message = MigrationMessage::fromArray($queued[0]);
        $this->assertSame($migration->getId(), $message->migration->getId());
        $this->assertSame($attemptId, $message->migration->getAttribute('attemptId'));
        $this->assertSame($migration->getId(), $events->getParam('migrationId'));
    }
}
