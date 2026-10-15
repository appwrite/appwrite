<?php

namespace Utopia\Tests\Unit\General;

use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Migration\Destinations\CSV as DestinationCSV;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Sources\CSV;
use Utopia\Storage\Device\Local;

/**
 * Test-friendly CSV destination
 */
class TestCSV extends DestinationCSV
{
    public function testableImport(array $resources, callable $callback): void
    {
        $this->import($resources, $callback);
    }

    public function getLocalRoot(): string
    {
        return $this->local->getRoot();
    }

    // Override shutdown to avoid transfer for testing
    #[Override]
    public function shutdown(): void
    {
        // Do nothing for testing - don't transfer files
    }
}

class CSVTest extends TestCase
{
    private const RESOURCES_DIR = __DIR__ . '/../../resources/csv/';

    public function testLegacyConstructorsAcceptPositionalAndNamedArguments(): void
    {
        $tempDir = sys_get_temp_dir() . '/csv_constructor_' . uniqid();
        $device = new Local($tempDir);
        $database = $this->createDatabase();

        $positionalSource = new CSV('database:table', 'input.csv', $device, $database);
        $namedSource = new CSV(
            resourceId: 'database:table',
            filePath: 'input.csv',
            device: $device,
            dbForProject: $database,
        );
        $positionalDestination = new TestCSV($device, 'database:table', '', 'output');
        $namedDestination = new TestCSV(
            deviceForFiles: $device,
            resourceId: 'database:table',
            directory: '',
            filename: 'output',
        );

        $this->assertInstanceOf(CSV::class, $positionalSource);
        $this->assertInstanceOf(CSV::class, $namedSource);
        $this->assertInstanceOf(DestinationCSV::class, $positionalDestination);
        $this->assertInstanceOf(DestinationCSV::class, $namedDestination);

        $this->recursiveDelete($positionalDestination->getLocalRoot());
        $this->recursiveDelete($namedDestination->getLocalRoot());
        $this->recursiveDelete($tempDir);
    }

    public function testConstructorsMatchOriginMainSignatures(): void
    {
        $this->assertSame([
            ['resourceId', 'string', false, null],
            ['filePath', 'string', false, null],
            ['device', 'Utopia\\Storage\\Device', false, null],
            ['dbForProject', '?Utopia\\Database\\Database', false, null],
            ['getDatabasesDB', '?callable', true, null],
        ], $this->constructorSignature(CSV::class));
        $this->assertSame([
            ['deviceForFiles', 'Utopia\\Storage\\Device', false, null],
            ['resourceId', 'string', false, null],
            ['directory', 'string', false, null],
            ['filename', 'string', false, null],
            ['allowedColumns', 'array', true, []],
            ['delimiter', 'string', true, ','],
            ['enclosure', 'string', true, '"'],
            ['escape', 'string', true, '"'],
            ['includeHeaders', 'bool', true, true],
        ], $this->constructorSignature(DestinationCSV::class));
    }

    public function testFactoriesKeepColonContainingResourceIdsSeparate(): void
    {
        $tempDir = sys_get_temp_dir() . '/csv_factory_' . uniqid();
        $device = new Local($tempDir);
        $database = $this->createDatabase();
        $databaseId = 'database:with:colon';
        $tableId = 'table:with:colon';

        $source = CSV::fromResourceIds($databaseId, $tableId, 'input.csv', $device, $database);
        $destination = DestinationCSV::fromResourceIds($device, $databaseId, $tableId, '', 'output');

        $this->assertSame($databaseId, $this->property($source, 'resourceId'));
        $this->assertSame($tableId, $this->property($source, 'resourceChildId'));
        $this->assertSame($databaseId, $this->property($destination, 'resourceId'));
        $this->assertSame($tableId, $this->property($destination, 'resourceChildId'));

        $local = $this->property($destination, 'local');
        $this->assertInstanceOf(Local::class, $local);
        $this->recursiveDelete($local->getRoot());
        $this->recursiveDelete($tempDir);
    }

    public function testDestinationSubclassChildSelectorPropertyRemainsCompatible(): void
    {
        $tempDir = sys_get_temp_dir() . '/csv_subclass_' . uniqid();
        $device = new Local($tempDir);
        $destination = new class ($device, 'database:table', '', 'output') extends DestinationCSV {
            protected $resourceChildId = ['external'];

            public function getExternalResourceChildId(): mixed
            {
                return $this->resourceChildId;
            }

            public function getLocalRoot(): string
            {
                return $this->local->getRoot();
            }
        };

        $this->assertSame(['external'], $destination->getExternalResourceChildId());

        $this->recursiveDelete($destination->getLocalRoot());
        $this->recursiveDelete($tempDir);
    }

    /**
     * @throws \ReflectionException
     */
    private function detectDelimiter($stream): string
    {
        $reflection = new \ReflectionClass(CSV::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $refMethod = $reflection->getMethod('delimiter');

        return $refMethod->invoke($instance, $stream);
    }

    public function testDetectDelimiter()
    {
        $cases = [
            ['file' => 'comma.csv',         'expected' => ','],
            ['file' => 'single_column.csv', 'expected' => ','], // fallback
            ['file' => 'empty.csv',         'expected' => ','], // fallback
            ['file' => 'quoted_fields.csv', 'expected' => ','],
            ['file' => 'semicolon.csv',     'expected' => ';'],
            ['file' => 'tab.csv',           'expected' => "\t"],
            ['file' => 'pipe.csv',          'expected' => '|'],
        ];

        foreach ($cases as $case) {
            $filepath = self::RESOURCES_DIR . $case['file'];
            $stream = fopen($filepath, 'r');
            $delimiter = $this->detectDelimiter($stream);
            fclose($stream);

            $this->assertSame($case['expected'], $delimiter, "Failed for {$case['file']}");
        }
    }

    public function testCSVExportBasic()
    {
        $tempDir = sys_get_temp_dir() . '/csv_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $exportDevice = new Local($tempDir);

        // Create CSV destination
        $csvDestination = new TestCSV($exportDevice, 'test_db:test_table_id', '', 'test_db_test_table_id');

        // Create test data
        $database = new Database('test_db');
        $table = new Table($database, 'test_table', 'test_table_id');

        $row1 = new Row('row1', $table, [
            'name' => 'John Doe',
            'age' => 30,
            'email' => 'john@example.com'
        ]);
        $row1->setPermissions(['read("user:123")']);

        $row2 = new Row('row2', $table, [
            'name' => 'Jane Smith',
            'age' => 25,
            'email' => 'jane@example.com'
        ]);
        $row2->setPermissions(['read("user:456")']);

        // Export the data
        $csvDestination->testableImport([$row1, $row2], function ($resources) {
            // Callback - verify resources are marked as successful
            foreach ($resources as $resource) {
                $this->assertSame('success', $resource->getStatus());
            }
        });

        $csvDestination->shutdown();

        // Verify CSV file was created in local temp directory
        $expectedFile = $csvDestination->getLocalRoot() . '/test_db_test_table_id.csv';
        $this->assertFileExists($expectedFile, 'CSV file should exist');

        // Use proper CSV parsing
        $handle = fopen($expectedFile, 'r');
        $this->assertNotFalse($handle);

        $header = fgetcsv($handle, 0, ',', '"', '"');
        $row1Data = fgetcsv($handle, 0, ',', '"', '"');
        $row2Data = fgetcsv($handle, 0, ',', '"', '"');
        fclose($handle);

        $this->assertNotFalse($header);
        $this->assertNotFalse($row1Data);
        $this->assertNotFalse($row2Data);

        // Check header
        $this->assertContains('$id', $header);
        $this->assertContains('$permissions', $header);
        $this->assertContains('$createdAt', $header);
        $this->assertContains('$updatedAt', $header);
        $this->assertContains('name', $header);
        $this->assertContains('age', $header);
        $this->assertContains('email', $header);

        // Check first row data
        $this->assertSame('row1', $row1Data[0]); // $id
        $this->assertStringContainsString('user:123', $row1Data[1]); // $permissions
        // $createdAt and $updatedAt are empty for test data
        $this->assertSame('John Doe', $row1Data[4]); // name
        $this->assertSame('30', $row1Data[5]); // age
        $this->assertSame('john@example.com', $row1Data[6]); // email

        // Cleanup
        if (is_dir($tempDir)) {
            $this->recursiveDelete($tempDir);
        }
    }

    public function testCSVExportWithSpecialCharacters()
    {
        $tempDir = sys_get_temp_dir() . '/csv_test_special_' . uniqid();
        $exportDevice = new Local($tempDir);

        $csvDestination = new TestCSV($exportDevice, 'test_db:test_table_id', '', 'test_db_test_table_id');

        $database = new Database('test_db');
        $table = new Table($database, 'test_table', 'test_table_id');

        // Test data with special characters that need escaping
        $row = new Row('special_row', $table, [
            'quote_field' => 'Text with "quotes"',
            'comma_field' => 'Text, with, commas',
            'newline_field' => "Text with\nnewlines",
            'mixed_field' => 'Text with "quotes", commas, and\nnewlines'
        ]);

        $csvDestination->testableImport([$row], function ($resources) {
        });
        $csvDestination->shutdown();

        $csvFile = $csvDestination->getLocalRoot() . '/test_db_test_table_id.csv';

        // Use proper CSV parsing
        $handle = fopen($csvFile, 'r');
        $this->assertNotFalse($handle);

        $header = fgetcsv($handle, 0, ',', '"', '"');
        $rowData = fgetcsv($handle, 0, ',', '"', '"');
        fclose($handle);

        $this->assertNotFalse($header);
        $this->assertNotFalse($rowData);

        // Verify special characters are properly handled
        // Indices are shifted by 2 due to $createdAt and $updatedAt
        $this->assertSame('Text with "quotes"', $rowData[4]); // quote_field
        $this->assertSame('Text, with, commas', $rowData[5]); // comma_field
        $this->assertSame("Text with\nnewlines", $rowData[6]); // newline_field
        $this->assertSame('Text with "quotes", commas, and\nnewlines', $rowData[7]); // mixed_field

        // Cleanup
        if (is_dir($tempDir)) {
            $this->recursiveDelete($tempDir);
        }
    }

    public function testCSVExportWithArrays()
    {
        $tempDir = sys_get_temp_dir() . '/csv_test_arrays_' . uniqid();
        $exportDevice = new Local($tempDir);

        $csvDestination = new TestCSV($exportDevice, 'test_db:test_table_id', '', 'test_db_test_table_id');

        $database = new Database('test_db');
        $table = new Table($database, 'test_table', 'test_table_id');

        $row = new Row('array_row', $table, [
            'tags' => ['php', 'csv', 'export'],
            'metadata' => ['key1' => 'value1', 'key2' => 'value2'],
            'empty_array' => [],
            'nested' => [['id' => 1], ['id' => 2]]
        ]);

        $csvDestination->testableImport([$row], function ($resources) {
        });
        $csvDestination->shutdown();

        $csvFile = $csvDestination->getLocalRoot() . '/test_db_test_table_id.csv';

        // Use proper CSV parsing
        $handle = fopen($csvFile, 'r');
        $this->assertNotFalse($handle);

        $header = fgetcsv($handle, 0, ',', '"', '"');
        $rowData = fgetcsv($handle, 0, ',', '"', '"');
        fclose($handle);

        $this->assertNotFalse($header);
        $this->assertNotFalse($rowData);

        // Arrays should be JSON encoded
        // Indices are shifted by 2 due to $createdAt and $updatedAt
        $this->assertSame('["php","csv","export"]', $rowData[4]); // tags
        $this->assertJson($rowData[5]); // metadata should be valid JSON
        $this->assertSame('', $rowData[6]); // empty_array
        $this->assertJson($rowData[7]); // nested should be valid JSON

        // Cleanup
        if (is_dir($tempDir)) {
            $this->recursiveDelete($tempDir);
        }
    }

    public function testCSVExportWithNullValues()
    {
        $tempDir = sys_get_temp_dir() . '/csv_test_nulls_' . uniqid();
        $exportDevice = new Local($tempDir);

        $csvDestination = new TestCSV($exportDevice, 'test_db:test_table_id', '', 'test_db_test_table_id');

        $database = new Database('test_db');
        $table = new Table($database, 'test_table', 'test_table_id');

        $row = new Row('null_row', $table, [
            'name' => 'Test',
            'null_field' => null,
            'empty_string' => '',
            'zero' => 0,
            'false_bool' => false
        ]);

        $csvDestination->testableImport([$row], function ($resources) {
        });
        $csvDestination->shutdown();

        $csvFile = $csvDestination->getLocalRoot() . '/test_db_test_table_id.csv';

        // Use proper CSV parsing
        $handle = fopen($csvFile, 'r');
        $this->assertNotFalse($handle);

        $header = fgetcsv($handle, 0, ',', '"', '"');
        $rowData = fgetcsv($handle, 0, ',', '"', '"');
        fclose($handle);

        $this->assertNotFalse($header);
        $this->assertNotFalse($rowData);

        // Indices are shifted by 2 due to $createdAt and $updatedAt
        $this->assertSame('Test', $rowData[4]); // name
        $this->assertSame('null', $rowData[5]); // null_field -> "null" string
        $this->assertSame('', $rowData[6]); // empty_string
        $this->assertSame('0', $rowData[7]); // zero
        $this->assertSame('false', $rowData[8]); // false_bool

        // Cleanup
        if (is_dir($tempDir)) {
            $this->recursiveDelete($tempDir);
        }
    }

    public function testCSVExportWithAllowedAttributes()
    {
        $tempDir = sys_get_temp_dir() . '/csv_test_filtered_' . uniqid();
        $exportDevice = new Local($tempDir);

        // Only allow specific attributes
        $csvDestination = new TestCSV($exportDevice, 'test_db:test_table_id', '', 'test_db_test_table_id', ['name', 'email']);

        $database = new Database('test_db');
        $table = new Table($database, 'test_table', 'test_table_id');

        $row = new Row('filtered_row', $table, [
            'name' => 'John Doe',
            'age' => 30,
            'email' => 'john@example.com',
            'secret' => 'should_not_appear'
        ]);

        $csvDestination->testableImport([$row], function ($resources) {
        });
        $csvDestination->shutdown();

        $csvFile = $csvDestination->getLocalRoot() . '/test_db_test_table_id.csv';

        // Use proper CSV parsing
        $handle = fopen($csvFile, 'r');
        $this->assertNotFalse($handle);

        $header = fgetcsv($handle, 0, ',', '"', '"');
        $rowData = fgetcsv($handle, 0, ',', '"', '"');
        fclose($handle);

        $this->assertNotFalse($header);
        $this->assertNotFalse($rowData);

        // Should have $id, $permissions, $createdAt, $updatedAt, and only allowed attributes
        $this->assertContains('$id', $header);
        $this->assertContains('$permissions', $header);
        $this->assertContains('$createdAt', $header);
        $this->assertContains('$updatedAt', $header);
        $this->assertContains('name', $header);
        $this->assertContains('email', $header);
        $this->assertNotContains('age', $header);
        $this->assertNotContains('secret', $header);

        // Cleanup
        if (is_dir($tempDir)) {
            $this->recursiveDelete($tempDir);
        }
    }

    public function testCSVExportImportCompatibility()
    {
        $tempDir = sys_get_temp_dir() . '/csv_test_compat_' . uniqid();
        $exportDevice = new Local($tempDir);

        // Export data
        $csvDestination = new TestCSV($exportDevice, 'test_db:test_table_id', '', 'test_db_test_table_id');

        $database = new Database('test_db');
        $table = new Table($database, 'test_table', 'test_table_id');

        $originalData = [
            'name' => 'John Doe',
            'age' => 30,
            'tags' => ['php', 'csv'],
            'metadata' => ['key' => 'value'],
            'null_field' => null,
            'empty_field' => '',
            'bool_field' => true
        ];

        $row = new Row('compat_row', $table, $originalData);
        $row->setPermissions(['read("user:123")']);

        $csvDestination->testableImport([$row], function ($resources) {
        });
        $csvDestination->shutdown();

        // Verify the exported CSV can be parsed by PHP's built-in CSV functions
        $csvFile = $csvDestination->getLocalRoot() . '/test_db_test_table_id.csv';
        $this->assertFileExists($csvFile);

        $handle = fopen($csvFile, 'r');
        $this->assertNotFalse($handle);

        $header = fgetcsv($handle, 0, ',', '"', '"');
        $data = fgetcsv($handle, 0, ',', '"', '"');
        fclose($handle);

        $this->assertNotFalse($header);
        $this->assertNotFalse($data);

        // Verify we can reconstruct the data
        $reconstructed = \array_combine($header, $data);

        $this->assertSame('compat_row', $reconstructed['$id']);
        $this->assertSame('John Doe', $reconstructed['name']);
        $this->assertSame('30', $reconstructed['age']);
        $this->assertSame('null', $reconstructed['null_field']); // null becomes "null" string
        $this->assertSame('', $reconstructed['empty_field']);
        $this->assertSame('true', $reconstructed['bool_field']); // bool becomes string
        // Check that createdAt and updatedAt are in the reconstructed data
        $this->assertArrayHasKey('$createdAt', $reconstructed);
        $this->assertArrayHasKey('$updatedAt', $reconstructed);

        // Arrays should be valid JSON that can be decoded
        $this->assertJson($reconstructed['tags']);
        $this->assertJson($reconstructed['metadata']);

        $tagsArray = json_decode($reconstructed['tags'], true);
        $metadataArray = json_decode($reconstructed['metadata'], true);

        $this->assertSame(['php', 'csv'], $tagsArray);
        $this->assertSame(['key' => 'value'], $metadataArray);

        // Cleanup
        if (is_dir($tempDir)) {
            $this->recursiveDelete($tempDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            if ($objects !== false) {
                foreach ($objects as $object) {
                    if ($object != "." && $object != "..") {
                        if (is_dir($dir."/".$object)) {
                            $this->recursiveDelete($dir."/".$object);
                        } else {
                            unlink($dir."/".$object);
                        }
                    }
                }
            }
            rmdir($dir);
        }
    }

    private function property(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);

        return $property->getValue($object);
    }

    /**
     * @param class-string $class
     * @return list<array{string, string, bool, mixed}>
     */
    private function constructorSignature(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $this->assertNotNull($constructor);

        return \array_map(static function (\ReflectionParameter $parameter): array {
            $hasDefault = $parameter->isDefaultValueAvailable();

            return [
                $parameter->getName(),
                (string) $parameter->getType(),
                $hasDefault,
                $hasDefault ? $parameter->getDefaultValue() : null,
            ];
        }, $constructor->getParameters());
    }

    private function createDatabase(): UtopiaDatabase
    {
        return new UtopiaDatabase(
            new MemoryAdapter(),
            new Cache(new MemoryCache()),
        );
    }
}
