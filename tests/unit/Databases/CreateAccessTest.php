<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\CreateAccess;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

final class CreateAccessTest extends TestCase
{
    private const string DATABASE_SEQUENCE = '1';
    private const string RELATED_TABLE = 'database_' . self::DATABASE_SEQUENCE . '_collection_books';

    public function testAGuestCreatingInAUsersOnlyCollectionGetsMainsAuthorizationDescription(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::guests()->toString());

        $error = $this->refusal(fn () => $this->access($authorization)->assert(
            self::collection([Permission::create(Role::users())]),
            PermissionType::Create,
        ));

        $this->assertSame(Exception::USER_UNAUTHORIZED, $error->getType());
        $this->assertSame(401, $error->getCode());
        $this->assertSame(
            'Missing "create" permission for role "users". Only "["any","guests"]" scopes are allowed and "["users"]" was given.',
            $error->getMessage(),
        );
    }

    public function testACallerHoldingTheCreateRolePasses(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::users()->toString());

        $this->access($authorization)->assert(self::collection([Permission::create(Role::users())]), PermissionType::Create);

        $this->addToAssertionCount(1);
    }

    public function testANewRelatedDocumentNeedsCreateOnItsCollection(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::users()->toString());

        $error = $this->refusal(fn () => $this->access($authorization)->assertRelated(
            ['$id' => 'book1'],
            self::collection([Permission::update(Role::users())], documentSecurity: true),
        ));

        $this->assertSame(Exception::USER_UNAUTHORIZED, $error->getType());
        $this->assertSame("No permissions provided for action 'create'", $error->getMessage());
    }

    public function testAnExistingRelatedDocumentNeedsUpdateOnItsCollection(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::users()->toString());

        $access = $this->access($authorization, [new Document(['$id' => 'book1'])]);

        $access->assertRelated(['$id' => 'book1'], self::collection([Permission::update(Role::users())], documentSecurity: true));
        $error = $this->refusal(fn () => $access->assertRelated(
            ['$id' => 'book1'],
            self::collection([Permission::create(Role::users())], documentSecurity: true),
        ));

        $this->assertSame(Exception::USER_UNAUTHORIZED, $error->getType());
    }

    public function testAnExistingRelatedDocumentIsRefusedWithoutDocumentSecurityAsOnMainEvenForAnApiKey(): void
    {
        $authorization = new Authorization(defaultStatus: false);

        $error = $this->refusal(fn () => $this->access($authorization, [new Document(['$id' => 'book1'])])->assertRelated(
            ['$id' => 'book1'],
            self::collection([Permission::update(Role::any())]),
        ));

        $this->assertSame(Exception::USER_UNAUTHORIZED, $error->getType());
        $this->assertSame(401, $error->getCode());
    }

    private function refusal(callable $call): Exception
    {
        try {
            $call();
        } catch (Exception $error) {
            return $error;
        }

        $this->fail('Main refused this create with 401');
    }

    /**
     * @param list<Document> $stored
     */
    private function access(Authorization $authorization, array $stored = []): CreateAccess
    {
        $documents = [];
        foreach ($stored as $document) {
            $documents[$document->getId()] = $document;
        }

        $database = new class ($documents) extends Database {
            /**
             * @param array<string, Document> $documents
             */
            public function __construct(private readonly array $documents)
            {
                parent::__construct(new Memory(), new Cache(new None()));
            }

            #[\Override]
            public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
            {
                if ($collection !== CreateAccessTest::relatedTable()) {
                    throw new \LogicException('Related documents are read from ' . CreateAccessTest::relatedTable());
                }

                return $this->documents[$id] ?? new Document();
            }
        };

        return new CreateAccess($authorization, $database, new Document(['$id' => 'library', '$sequence' => self::DATABASE_SEQUENCE]));
    }

    public static function relatedTable(): string
    {
        return self::RELATED_TABLE;
    }

    /**
     * @param list<string> $permissions
     */
    private static function collection(array $permissions, bool $documentSecurity = false): Document
    {
        return new Document([
            '$id' => 'books',
            '$sequence' => 'books',
            '$permissions' => $permissions,
            'documentSecurity' => $documentSecurity,
        ]);
    }
}
