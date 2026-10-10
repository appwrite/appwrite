<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Get;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\XList;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class TransactionReadTest extends TestCase
{
    private Authorization $authorization;

    private Database $dbForProject;

    private StagedTransactionState $state;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());

        $database = new Document(['$id' => 'tdb', '$sequence' => '1', 'type' => DATABASE_TYPE_TABLESDB, 'enabled' => true]);
        $table = new Document(['$id' => 'public', '$sequence' => '1', 'enabled' => true, 'attributes' => [], 'indexes' => []]);
        $this->dbForProject = $this->createStub(Database::class);
        $this->dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection): Document => match ($collection) {
                'databases' => $database,
                'database_1' => $table,
                default => new Document(),
            }
        );
        $this->dbForProject->method('getAuthorization')->willReturn($this->authorization);
        $this->state = new StagedTransactionState(new Document(['title' => 'from txn', '$id' => 'txn1', '$permissions' => []]));
    }

    public function testARowStagedInATransactionCarriesItsDatabaseAndTableAsOnMain(): void
    {
        $read = null;
        (new Get())->action(
            'tdb',
            'public',
            'txn1',
            [],
            'transaction-1',
            $this->response($read),
            $this->dbForProject,
            fn (): Database => $this->store(),
            new Context(),
            $this->state,
            $this->authorization,
            new User(),
        );

        $this->assertInstanceOf(Document::class, $read);
        $this->assertSame('tdb', $read->getAttribute('$databaseId'), 'Typed SDKs require $databaseId on every row');
        $this->assertSame('public', $read->getAttribute('$tableId'), 'Typed SDKs require $tableId on every row');
    }

    public function testRowsListedInATransactionCarryTheirDatabaseAndTableAsOnMain(): void
    {
        $listed = null;
        (new XList())->action(
            'tdb',
            'public',
            [],
            'transaction-1',
            true,
            0,
            $this->response($listed),
            $this->dbForProject,
            new User(),
            fn (): Database => $this->store(),
            new Context(),
            $this->state,
            $this->authorization,
        );

        $this->assertInstanceOf(Document::class, $listed);
        [$row] = $listed->getAttribute('rows');
        $this->assertSame('tdb', $row->getAttribute('$databaseId'));
        $this->assertSame('public', $row->getAttribute('$tableId'));
    }

    private function response(?Document &$captured): Response
    {
        $response = $this->createStub(Response::class);
        $response->method('dynamic')->willReturnCallback(static function (Document $document) use (&$captured): void {
            $captured = $document;
        });
        $response->method('addHeader')->willReturnSelf();

        return $response;
    }

    private function store(): Database
    {
        return (new Database(new Memory(), new Cache(new None())))->setAuthorization($this->authorization);
    }
}
