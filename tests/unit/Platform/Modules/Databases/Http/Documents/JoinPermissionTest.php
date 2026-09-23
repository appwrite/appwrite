<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Method;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * A joined collection is readable exactly when listing it directly would be: with its collection-level read,
 * or through document security, where the database library then returns only the rows the caller may read.
 * A collection with neither is refused before the query runs.
 */
final class JoinPermissionTest extends TestCase
{
    private const string DATABASE_ID = 'shop';

    private Authorization $authorization;

    /**
     * @var list<array<Query>>
     */
    private array $searches = [];

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());
        $this->authorization->addRole(Role::users()->toString());
        $this->authorization->addRole(Role::user('reader')->toString());
    }

    public function testJoinOfACollectionReadableAtCollectionLevelReachesTheQuery(): void
    {
        $this->list('shared');

        $this->assertSame('database_1_collection_2', $this->joinedTable());
    }

    public function testJoinOfADocumentSecurityCollectionWithoutCollectionReadReachesTheQuery(): void
    {
        $this->list('owned');

        $this->assertSame('database_1_collection_3', $this->joinedTable(), 'listing it directly returns the rows the caller holds document read on, so joining it must too');
    }

    public function testJoinOfACollectionTheCallerCannotListIsUnauthorized(): void
    {
        try {
            $this->list('closed');
        } catch (Exception $error) {
            $this->assertSame(Exception::USER_UNAUTHORIZED, $error->getType());
            $this->assertSame(401, $error->getCode());
            $this->assertSame([], $this->searches, 'the query must not run');

            return;
        }

        $this->fail('A join to a collection without collection-level read and without document security must be a 401 ' . Exception::USER_UNAUTHORIZED);
    }

    public function testJoinOfADisabledCollectionIsNotFound(): void
    {
        try {
            $this->list('disabled');
        } catch (Exception $error) {
            $this->assertSame(Exception::COLLECTION_NOT_FOUND, $error->getType());
            $this->assertSame([], $this->searches);

            return;
        }

        $this->fail('A join to a disabled collection must be a 404 ' . Exception::COLLECTION_NOT_FOUND);
    }

    public function testApiKeyJoinsACollectionItCannotListAsAUser(): void
    {
        $this->authorization->addRole(User::ROLE_KEYS);

        $this->list('closed');

        $this->assertSame('database_1_collection_4', $this->joinedTable(), 'API keys are privileged across the project');
    }

    private function list(string $joined): void
    {
        (new XList())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'customers',
            queries: [Query::join($joined, '$id', 'customerId', '=', 'ord')->toString()],
            transactionId: null,
            includeTotal: false,
            ttl: 0,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            user: new User(['$id' => 'reader']),
            getDatabasesDB: fn (): Database => $this->documentsDatabase(),
            usage: new Context(),
            transactionState: $this->createStub(TransactionState::class),
            authorization: $this->authorization,
        );
    }

    private function joinedTable(): string
    {
        $this->assertCount(1, $this->searches, 'the list must run');
        $joins = \array_values(\array_filter(
            $this->searches[0],
            static fn (Query $query): bool => $query->getMethod() === Method::Join,
        ));
        $this->assertCount(1, $joins);

        return $joins[0]->getAttribute();
    }

    private function projectDatabase(): Database
    {
        $metadata = [
            'databases' => [
                self::DATABASE_ID => new Document([
                    '$id' => self::DATABASE_ID,
                    '$sequence' => '1',
                    'type' => DATABASE_TYPE_LEGACY,
                    'enabled' => true,
                ]),
            ],
            'database_1' => [
                'customers' => self::collection('customers', '1', [Permission::read(Role::any())], documentSecurity: true),
                'shared' => self::collection('shared', '2', [Permission::read(Role::any())], documentSecurity: true),
                'owned' => self::collection('owned', '3', [Permission::create(Role::any())], documentSecurity: true),
                'closed' => self::collection('closed', '4', [Permission::create(Role::any())], documentSecurity: false),
                'disabled' => self::collection('disabled', '5', [Permission::read(Role::any())], documentSecurity: true, enabled: false),
            ],
        ];

        $database = $this->createStub(Database::class);
        $database->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => $metadata[$collection][$id] ?? new Document()
        );

        return $database;
    }

    private function documentsDatabase(): Database
    {
        $database = $this->createStub(Database::class);
        $database->method('find')->willReturnCallback(function (string $collection, array $queries = []): array {
            $this->searches[] = $queries;

            return [];
        });
        $database->method('skipRelationships')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $database;
    }

    /**
     * @param list<string> $permissions
     */
    private static function collection(string $id, string $sequence, array $permissions, bool $documentSecurity, bool $enabled = true): Document
    {
        return new Document([
            '$id' => $id,
            '$sequence' => $sequence,
            '$permissions' => $permissions,
            'enabled' => $enabled,
            'documentSecurity' => $documentSecurity,
            'attributes' => [],
        ]);
    }
}
