<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Migrations;

use Appwrite\Platform\Modules\Migrations\Claim;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Mongo\Client;

final class ClaimTest extends TestCase
{
    /** @return \Iterator<string, array{string}> */
    public static function adapters(): \Iterator
    {
        yield 'SQLite' => ['sqlite'];

        if (\getenv('_APP_TEST_MIGRATION_MONGO_HOST')) {
            yield 'standalone MongoDB' => ['mongodb'];
        }
    }

    #[DataProvider('adapters')]
    public function testStaleSnapshotCannotCompleteNewerVersionWithIdenticalTimestamp(string $adapter): void
    {
        $name = 'migration_claim_' . \bin2hex(\random_bytes(12));
        $connection = match ($adapter) {
            'sqlite' => new SQLite(new PDO('sqlite::memory:')),
            'mongodb' => new Mongo((new Client(
                database: $name,
                host: \getenv('_APP_TEST_MIGRATION_MONGO_HOST') ?: '',
                port: (int) (\getenv('_APP_TEST_MIGRATION_MONGO_PORT') ?: 27017),
                user: \getenv('_APP_TEST_MIGRATION_MONGO_USER') ?: '',
                password: \getenv('_APP_TEST_MIGRATION_MONGO_PASSWORD') ?: '',
                authSource: 'admin',
            ))->connect()),
        };
        $database = new Database($connection, new Cache(new NoCache()));
        $database
            ->setDatabase($name)
            ->setNamespace($name)
            ->setAuthorization(new Authorization());
        $database->create();

        try {
            $database->createCollection(new Collection(
                id: 'migrations',
                attributes: [
                    Attribute::string('attemptId', size: Database::LENGTH_KEY),
                    Attribute::string('status'),
                    Attribute::string('stage'),
                ],
                permissions: [
                    Permission::create(Role::any()),
                    Permission::delete(Role::any()),
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
            ));
            $active = $database->createDocument('migrations', new Document([
                '$id' => 'migration',
                'attemptId' => 'attempt',
                'status' => 'processing',
                'stage' => 'processing',
            ]));
            $database->setPreserveDates(true);
            $newer = $database->updateDocument('migrations', $active->getId(), new Document([
                '$updatedAt' => $active->getUpdatedAt(),
                'stage' => 'migrating',
            ]), expectedVersion: $active->getVersion());
            $this->assertSame($active->getUpdatedAt(), $newer->getUpdatedAt());
            $this->assertNotSame($active->getVersion(), $newer->getVersion());

            $active->setAttribute('status', 'completed');
            $active->setAttribute('stage', 'finished');
            $this->assertNotInstanceOf(Document::class, (new Claim($database))->persist($active));

            $stored = $database->getDocument('migrations', $active->getId());
            $this->assertSame('processing', $stored->getAttribute('status'));
            $this->assertSame('migrating', $stored->getAttribute('stage'));
            $this->assertSame($newer->getVersion(), $stored->getVersion());
        } finally {
            $database->delete();
        }
    }
}
