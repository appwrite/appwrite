<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Database\Provisioner;
use Appwrite\Platform\Tasks\Encryption;
use Generator;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\PermissionType;

final class EncryptionTest extends TestCase
{
    private const array VARIABLES = ['_APP_OPENSSL_KEY_CURRENT', '_APP_OPENSSL_KEY_V2'];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->environment[$name] = \getenv($name);
        }
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testOnlyDatabasesWithTableMetadataAreWalked(): void
    {
        $database = new CursorRecordingDatabase(
            rows: [
                'projects' => [new Document(['$id' => 'project', '$sequence' => '1'])],
                'databases' => [
                    new Document(['$id' => 'tables', '$sequence' => '7', 'type' => 'tablesdb']),
                    new Document(['$id' => 'postgres', '$sequence' => '8', 'type' => 'postgresql']),
                    new Document(['$id' => 'orphan', '$sequence' => '9', 'type' => 'legacy']),
                ],
            ],
            collections: ['projects', 'databases', 'database_7'],
        );
        $provisioner = $this->createStub(Provisioner::class);
        $provisioner->method('tenant')->willReturn($database);

        // database_8 is a dedicated engine's, kept outside Appwrite's metadata, and database_9's collection is gone:
        // walking either throws `Collection not found` and ends the run before the project is done
        new Encryption()->action('true', '', 100, $database, fn () => $database, $provisioner);

        $this->assertSame(['projects', 'databases', 'database_7'], $database->cursors);
    }
}

final class CursorRecordingDatabase extends Database
{
    /** @var list<string> */
    public array $cursors = [];

    /**
     * @param array<string, list<Document>> $rows
     * @param list<string> $collections
     */
    public function __construct(private readonly array $rows, private readonly array $collections)
    {
    }

    #[\Override]
    public function findCollection(string $collection): ?Collection
    {
        return \in_array($collection, $this->collections, true) ? Collection::fromArray(['$id' => $collection]) : null;
    }

    #[\Override]
    public function cursor(string $collection, array $queries = [], int $batchSize = self::CURSOR_BATCH_SIZE, PermissionType $forPermission = PermissionType::Read): Generator
    {
        if (!\in_array($collection, $this->collections, true)) {
            throw new NotFoundException('Collection not found');
        }

        $this->cursors[] = $collection;

        yield from $this->rows[$collection] ?? [];
    }
}
