<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations\Http;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Firebase\Create;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../../../app/init.php';

final class CreateBeforeV26Test extends TestCase
{
    private string|false $key;

    protected function setUp(): void
    {
        $this->key = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=migration-create-test-key');
    }

    protected function tearDown(): void
    {
        \putenv($this->key === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->key);
    }

    public function testAMigrationIsCreatedAndQueuedBeforeMigrateRunsAsOnMain(): void
    {
        $database = $this->projectBeforeV26();
        $publisher = new MockPublisher();
        $response = $this->createMock(Response::class);
        $response->method('setStatusCode')->willReturnSelf();
        $response->expects($this->once())->method('setStatusCode')->with(Response::STATUS_CODE_ACCEPTED);
        $response->expects($this->once())->method('dynamic');

        (new Create())->action(
            resources: ['user'],
            serviceAccount: \json_encode(['project_id' => 'firebase', 'client_email' => 'a@b.c', 'private_key' => 'key']) ?: '',
            response: $response,
            dbForProject: $database,
            project: new Document(['$id' => 'project-1']),
            platform: [],
            queueForEvents: $this->createStub(Event::class),
            publisherForMigrations: new MigrationPublisher($publisher, new Queue('migrations')),
            locks: static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
        );

        $migrations = $database->find('migrations');
        $this->assertCount(1, $migrations, 'Main stored the migration; before migrate it must not answer 503');
        $this->assertSame('pending', $migrations[0]->getAttribute('status'));
        $this->assertSame('init', $migrations[0]->getAttribute('stage'));
        $events = $publisher->getEvents('migrations') ?? [];
        $this->assertCount(1, $events, 'Main queued the migration');
        $this->assertSame($migrations[0]->getId(), MigrationMessage::fromArray($events[0])->migration->getId());
    }

    private function projectBeforeV26(): Database
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationsBeforeV26')
            ->setNamespace('migrations_before_v26_' . \uniqid());
        $database->create();

        $collections = Config::getParam('collections', [])['projects'];
        foreach (['databases' => ['migrationId', 'migrationAttemptId'], 'migrations' => ['attemptId']] as $id => $ownership) {
            $database->createCollection(Collection::create(
                id: $id,
                attributes: \array_values(\array_filter(
                    $collections[$id]['attributes'],
                    static fn (Attribute $attribute): bool => !\in_array($attribute->key, $ownership, true),
                )),
                permissions: [Permission::create(Role::any()), Permission::read(Role::any()), Permission::update(Role::any())],
                documentSecurity: false,
            ));
        }

        return $database;
    }
}
