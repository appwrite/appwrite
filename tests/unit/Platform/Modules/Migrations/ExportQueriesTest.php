<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\CSV\Exports\Create as CreateCSVExport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\JSON\Exports\Create as CreateJSONExport;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../app/init.php';

final class ExportQueriesTest extends TestCase
{
    private const string VALIDATED = 'The queries passed validation';

    /**
     * @return \Iterator<string, array{string, string, bool}>
     */
    public static function exports(): \Iterator
    {
        yield 'csv, a declared attribute' => ['csv', 'title', true];
        yield 'csv, an undeclared attribute' => ['csv', 'missing', false];
        yield 'json on a legacy database, a declared attribute' => [DATABASE_TYPE_LEGACY, 'title', true];
        yield 'json on a legacy database, an undeclared attribute' => [DATABASE_TYPE_LEGACY, 'missing', false];
        yield 'json on a schemaless database, an undeclared attribute' => [DATABASE_TYPE_DOCUMENTSDB, 'missing', true];
    }

    #[DataProvider('exports')]
    public function testExportQueriesAreValidatedAgainstTheCollection(string $export, string $attribute, bool $valid): void
    {
        $databaseType = $export === 'csv' ? DATABASE_TYPE_LEGACY : $export;
        $queries = [Query::equal($attribute, ['Dune'])->toString()];

        try {
            if ($export === 'csv') {
                (new CreateCSVExport())->action('library', 'books', 'books.csv', [], $queries, ',', '"', '\\', true, false, ...$this->dependencies($databaseType));
            } else {
                (new CreateJSONExport())->action('library', 'books', 'books.json', [], $queries, false, ...$this->dependencies($databaseType));
            }
            $this->fail('The export must stop at the stubbed migration write');
        } catch (\LogicException $exception) {
            $this->assertTrue($valid, 'The queries must be refused');
            $this->assertSame(self::VALIDATED, $exception->getMessage());
        } catch (Exception $exception) {
            $this->assertFalse($valid, $exception->getMessage());
            $this->assertSame(Exception::GENERAL_QUERY_INVALID, $exception->getType());
        }
    }

    /**
     * @return list<mixed>
     */
    private function dependencies(string $databaseType): array
    {
        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document(['$id' => 'default']));

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('profile')->willReturn((new Database(new Memory(), new Cache(new NoCache())))->profile());
        $dbForProject->method('getCollection')->willReturnCallback(
            static fn (string $collection): Collection => Collection::create(id: $collection, attributes: [
                Attribute::string('migrationId'),
                Attribute::string('migrationAttemptId'),
                Attribute::string('attemptId'),
            ])
        );
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => match ([$collection, $id]) {
                ['databases', 'library'] => new Document(['$id' => 'library', '$sequence' => '1', 'type' => $databaseType]),
                ['database_1', 'books'] => new Document([
                    '$id' => 'books',
                    '$sequence' => '2',
                    'attributes' => [Attribute::string('title', 128)->toDocument()],
                    'indexes' => [],
                ]),
                default => new Document(),
            }
        );
        $dbForProject->method('createDocument')->willThrowException(new \LogicException(self::VALIDATED));

        return [
            new Document(['$id' => 'user', '$sequence' => '3']),
            $this->createStub(Response::class),
            $dbForProject,
            $dbForPlatform,
            $authorization,
            new Document(['$id' => 'project', '$sequence' => '4']),
            [],
            $this->createStub(Event::class),
            $this->createStub(MigrationPublisher::class),
            static fn (): null => null,
        ];
    }
}
