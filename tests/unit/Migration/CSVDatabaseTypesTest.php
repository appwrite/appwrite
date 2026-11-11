<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Action;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\CSV\Exports\Create as ExportCreate;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\CSV\Imports\Create as ImportCreate;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;
use Utopia\Storage\Device\Local;

require_once __DIR__ . '/../../../app/init/constants.php';

final class CSVDatabaseTypesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (Config::getParam('errors') === null) {
            Config::setParam('errors', include __DIR__ . '/../../../app/config/errors.php');
        }
        if (Config::getParam('pools-migrations') === null) {
            Config::setParam('pools-migrations', []);
        }
    }

    public function testCsvAllowedDatabaseTypesIncludesDocumentsDb(): void
    {
        $this->assertContains(DATABASE_TYPE_LEGACY, CSV_ALLOWED_DATABASE_TYPES);
        $this->assertContains(DATABASE_TYPE_TABLESDB, CSV_ALLOWED_DATABASE_TYPES);
        $this->assertContains(DATABASE_TYPE_DOCUMENTSDB, CSV_ALLOWED_DATABASE_TYPES);
        $this->assertContains(DATABASE_TYPE_VECTORSDB, CSV_ALLOWED_DATABASE_TYPES);
    }

    public function testActionMapsDocumentsDbToDocumentsDbTransferGroupAndResource(): void
    {
        $action = (new \ReflectionClass(ExportCreate::class))->newInstanceWithoutConstructor();
        $transferGroupMethod = new \ReflectionMethod(Action::class, 'transferGroupForDatabaseType');
        $resourceTypeMethod = new \ReflectionMethod(Action::class, 'resourceTypeForDatabaseType');

        $this->assertSame(
            Transfer::GROUP_DATABASES_DOCUMENTS_DB,
            $transferGroupMethod->invoke($action, DATABASE_TYPE_DOCUMENTSDB),
        );
        $this->assertSame(
            Resource::TYPE_DATABASE_DOCUMENTSDB,
            $resourceTypeMethod->invoke($action, DATABASE_TYPE_DOCUMENTSDB),
        );
    }

    public function testCsvExportRejectsUnsupportedDatabaseType(): void
    {
        $action = (new \ReflectionClass(ExportCreate::class))->newInstanceWithoutConstructor();
        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static function (string $collection, string $id): Document {
                if ($collection === 'databases') {
                    return new Document([
                        '$id' => $id,
                        '$sequence' => 1,
                        'type' => 'unsupported_type',
                    ]);
                }

                return new Document([
                    '$id' => $id,
                    '$sequence' => 2,
                ]);
            }
        );

        try {
            $action->action(
                databaseId: 'db1',
                collectionId: 'col1',
                filename: 'export.csv',
                columns: [],
                queries: [],
                delimiter: ',',
                enclosure: '"',
                escape: '"',
                header: true,
                notify: false,
                user: new Document(['$id' => 'user1']),
                request: $this->createStub(Request::class),
                response: $this->createStub(Response::class),
                dbForProject: $dbForProject,
                dbForPlatform: $this->createStub(Database::class),
                authorization: new Authorization(),
                project: new Document(['$id' => 'proj1', '$sequence' => 1]),
                platform: [],
                queueForEvents: $this->createStub(Event::class),
                publisherForMigrations: $this->createStub(MigrationPublisher::class),
            );
            $this->fail('Expected Exception::MIGRATION_DATABASE_TYPE_UNSUPPORTED');
        } catch (Exception $e) {
            $this->assertSame(Exception::MIGRATION_DATABASE_TYPE_UNSUPPORTED, $e->getType());
        }
    }

    public function testCsvImportAllowsDocumentsDb(): void
    {
        $filesRoot = sys_get_temp_dir() . '/csv_imp_files_' . uniqid();
        $migrationsRoot = sys_get_temp_dir() . '/csv_imp_mig_' . uniqid();
        mkdir($filesRoot, 0755, true);
        mkdir($migrationsRoot, 0755, true);
        $csvPath = $filesRoot . '/import.csv';
        file_put_contents($csvPath, "\$id,name\n1,Alice\n");

        try {
            $action = (new \ReflectionClass(ImportCreate::class))->newInstanceWithoutConstructor();
            $dbForProject = $this->createStub(Database::class);
            $dbForProject->method('getDocument')->willReturnCallback(
                static function (string $collection, string $id) use ($csvPath): Document {
                    if ($collection === 'buckets') {
                        return new Document([
                            '$id' => $id,
                            '$sequence' => 10,
                        ]);
                    }
                    if ($collection === 'bucket_10') {
                        return new Document([
                            '$id' => $id,
                            'name' => 'import.csv',
                            'mimeType' => 'text/csv',
                            'sizeOriginal' => 17,
                            'path' => $csvPath,
                        ]);
                    }
                    if ($collection === 'databases') {
                        return new Document([
                            '$id' => $id,
                            '$sequence' => 1,
                            'type' => DATABASE_TYPE_DOCUMENTSDB,
                        ]);
                    }
                    if ($collection === 'database_1') {
                        return new Document([
                            '$id' => $id,
                            '$sequence' => 2,
                        ]);
                    }

                    return new Document();
                }
            );

            $createdMigration = null;
            $dbForProject->method('createDocument')->willReturnCallback(
                static function (string $collection, Document $doc) use (&$createdMigration): Document {
                    $createdMigration = $doc;

                    return $doc;
                }
            );

            $response = $this->createMock(Response::class);
            $response->expects($this->once())
                ->method('setStatusCode')
                ->with(Response::STATUS_CODE_ACCEPTED)
                ->willReturnSelf();
            $response->expects($this->once())
                ->method('dynamic');

            $publisher = $this->createMock(MigrationPublisher::class);
            $publisher->expects($this->once())
                ->method('enqueue')
                ->willReturn('job1');

            $events = $this->createStub(Event::class);
            $events->method('setParam')->willReturnSelf();
            $events->method('setPayload')->willReturnSelf();

            $deviceForFiles = new class ($filesRoot) extends Local {
                public function getFileMimeType(string $path): string
                {
                    return 'text/csv';
                }
            };

            $deviceForMigrations = new class ($migrationsRoot) extends Local {
                public function getPath(string $filename, ?string $prefix = null): string
                {
                    return $this->getRoot() . '/' . \ltrim($filename, '/\\');
                }
            };

            $action->action(
                bucketId: 'bucket1',
                fileId: 'file1',
                databaseId: 'db1',
                collectionId: 'col1',
                internalFile: false,
                onDuplicate: 'error',
                request: $this->createStub(Request::class),
                response: $response,
                dbForProject: $dbForProject,
                dbForPlatform: $this->createStub(Database::class),
                authorization: new Authorization(),
                project: new Document(['$id' => 'proj1', '$sequence' => 1]),
                platform: [],
                user: new Document(['$id' => 'user1']),
                deviceForFiles: $deviceForFiles,
                deviceForMigrations: $deviceForMigrations,
                queueForEvents: $events,
                publisherForMigrations: $publisher,
            );

            $this->assertInstanceOf(Document::class, $createdMigration);
            $this->assertSame(
                Transfer::GROUP_DOCUMENTSDB_RESOURCES,
                $createdMigration->getAttribute('resources'),
            );
        } finally {
            $this->deleteDirectory($filesRoot);
            $this->deleteDirectory($migrationsRoot);
        }
    }

    public function testCsvImportRejectsUnsupportedDatabaseType(): void
    {
        $filesRoot = sys_get_temp_dir() . '/csv_imp_bad_files_' . uniqid();
        $migrationsRoot = sys_get_temp_dir() . '/csv_imp_bad_mig_' . uniqid();
        mkdir($filesRoot, 0755, true);
        mkdir($migrationsRoot, 0755, true);
        $csvPath = $filesRoot . '/import.csv';
        file_put_contents($csvPath, "\$id,name\n1,Alice\n");

        try {
            $action = (new \ReflectionClass(ImportCreate::class))->newInstanceWithoutConstructor();
            $dbForProject = $this->createStub(Database::class);
            $dbForProject->method('getDocument')->willReturnCallback(
                static function (string $collection, string $id) use ($csvPath): Document {
                    if ($collection === 'buckets') {
                        return new Document([
                            '$id' => $id,
                            '$sequence' => 10,
                        ]);
                    }
                    if ($collection === 'bucket_10') {
                        return new Document([
                            '$id' => $id,
                            'name' => 'import.csv',
                            'mimeType' => 'text/csv',
                            'sizeOriginal' => 17,
                            'path' => $csvPath,
                        ]);
                    }
                    if ($collection === 'databases') {
                        return new Document([
                            '$id' => $id,
                            '$sequence' => 1,
                            'type' => 'unsupported_type',
                        ]);
                    }
                    if ($collection === 'database_1') {
                        return new Document([
                            '$id' => $id,
                            '$sequence' => 2,
                        ]);
                    }

                    return new Document();
                }
            );

            try {
                $action->action(
                    bucketId: 'bucket1',
                    fileId: 'file1',
                    databaseId: 'db1',
                    collectionId: 'col1',
                    internalFile: false,
                    onDuplicate: 'error',
                    request: $this->createStub(Request::class),
                    response: $this->createStub(Response::class),
                    dbForProject: $dbForProject,
                    dbForPlatform: $this->createStub(Database::class),
                    authorization: new Authorization(),
                    project: new Document(['$id' => 'proj1', '$sequence' => 1]),
                    platform: [],
                    user: new Document(['$id' => 'user1']),
                    deviceForFiles: new Local($filesRoot),
                    deviceForMigrations: new Local($migrationsRoot),
                    queueForEvents: $this->createStub(Event::class),
                    publisherForMigrations: $this->createStub(MigrationPublisher::class),
                );
                $this->fail('Expected Exception::MIGRATION_DATABASE_TYPE_UNSUPPORTED');
            } catch (Exception $e) {
                $this->assertSame(Exception::MIGRATION_DATABASE_TYPE_UNSUPPORTED, $e->getType());
            }
        } finally {
            $this->deleteDirectory($filesRoot);
            $this->deleteDirectory($migrationsRoot);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    $this->deleteDirectory($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }
}
