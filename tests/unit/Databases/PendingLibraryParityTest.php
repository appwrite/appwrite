<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event\Document\Purged;
use Utopia\Database\Event\Domain;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Hook\Lifecycle;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Query\Builder\PostgreSQL;
use Utopia\Query\Query as BaseQuery;

/**
 * Main's behaviour that only utopia-php/database 8.0.1 can restore; each case is specified in
 * .logs/no-breaking-library-fixes.md. They fail on 8.0.0 by design; exclude the group until 8.0.1 lands.
 */
#[Group('pending-database-8.0.1')]
final class PendingLibraryParityTest extends TestCase
{
    private const string PENDING = 'Pending utopia-php/database 8.0.1 (no-breaking-library-fixes.md): ';

    private const string COLLECTION = 'movies';

    private Database $database;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $this->database = new Database(new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()));
        $this->database
            ->setAuthorization($authorization)
            ->setDatabase('pending')
            ->setNamespace('pending_' . \uniqid());
        $this->database->create();
        $this->database->createCollection(Collection::create(
            id: self::COLLECTION,
            attributes: [Attribute::string('title', size: 64)],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any()), Permission::update(Role::any())],
        ));
        foreach (['a', 'b', 'c'] as $id) {
            $this->database->createDocument(self::COLLECTION, new Document(['$id' => $id, 'title' => 'Movie ' . $id]));
        }
    }

    public function testTheUidDescriptionKeepsMainsText(): void
    {
        $this->assertSame(
            'UID must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can\'t start with a leading underscore',
            (new UID())->getDescription(),
            self::PENDING . 'every invalid ID and cursor answers with the UID description',
        );
    }

    public function testARandomOrderIgnoresTheCursorAsOnMain(): void
    {
        $cursor = $this->database->getDocument(self::COLLECTION, 'a');

        try {
            $documents = $this->database->find(self::COLLECTION, [Query::orderRandom(), Query::cursorAfter($cursor)]);
        } catch (\Throwable $error) {
            $this->fail(self::PENDING . 'orderRandom with a cursor answered 200 with every document on main, not ' . $error->getMessage());
        }

        $this->assertCount(3, $documents, self::PENDING . 'main ignored the cursor of a random order');
    }

    public function testSelectingTheTenantWithoutSharedTablesKeepsMainsMessage(): void
    {
        try {
            $this->database->find(self::COLLECTION, [Query::select(['$tenant'])]);
            $this->fail(self::PENDING . 'selecting $tenant without shared tables must be refused');
        } catch (QueryException $error) {
            $this->assertSame('Cannot select attributes: $tenant', $error->getMessage(), self::PENDING . 'main refused it with this message');
        }
    }

    public function testAQuotedPostgresSearchMatchesEveryWordAsOnMain(): void
    {
        $result = (new PostgreSQL())
            ->from('t')
            ->filter([BaseQuery::search('body', '"hello world"')])
            ->build();

        $this->assertSame(["'hello world'"], $result->bindings, self::PENDING . 'a quoted PostgreSQL search matched documents holding every word, not the adjacent phrase');
    }

    public function testAFailingPurgeListenerRollsTheWriteBackAsOnMain(): void
    {
        $this->database->addHook(new class () implements Lifecycle {
            public function handle(Domain $event): void
            {
                if ($event instanceof Purged) {
                    throw new \RuntimeException('Cache purge relay unavailable');
                }
            }
        });

        try {
            $this->database->updateDocument(self::COLLECTION, 'a', new Document(['title' => 'Changed']));
            $this->fail('A failing purge listener must fail the write');
        } catch (\RuntimeException) {
        }

        $this->assertSame(
            'Movie a',
            $this->database->getAuthorization()->skip(fn () => $this->database->getDocument(self::COLLECTION, 'a'))->getAttribute('title'),
            self::PENDING . 'main purged inside the write transaction, so a failing purge listener rolled the write back',
        );
    }
}
