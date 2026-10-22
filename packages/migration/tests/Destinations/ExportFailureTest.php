<?php

namespace Utopia\Migration\Tests\Destinations;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Destinations\CSV;
use Utopia\Migration\Destinations\JSON;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Tests\Adapters\MockSource;
use Utopia\Migration\Transfer;
use Utopia\Storage\Device\Local;

final class ExportFailureTest extends TestCase
{
    private string $root;

    #[Override]
    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/export_failure_' . \uniqid();
        \mkdir($this->root . '/exports', 0755, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Local($this->root))->delete($this->root, true);
    }

    /**
     * @return array<string, array{0: class-string<CSV|JSON>, 1: string}>
     */
    public static function destinations(): array
    {
        return [
            'csv' => [CSV::class, 'export.csv'],
            'json' => [JSON::class, 'export.json'],
        ];
    }

    /**
     * @param class-string<CSV|JSON> $class
     */
    #[DataProvider('destinations')]
    public function testFailedExportLeavesNoFile(string $class, string $filename): void
    {
        $device = new Local($this->root);
        $destination = $class::fromResourceIds($device, 'database', 'table', 'exports', 'export');
        $path = $device->getPath('exports/' . $filename);

        $source = new MockSource();
        $source->pushMockResource(new Row('row', new Table(new Database('database'), 'table', 'table'), ['name' => 'value']));
        (new Transfer($source, $destination))->run([Resource::TYPE_ROW], fn () => null);
        $destination->shutdown();
        $this->assertFileExists($path);

        $destination->error();

        $this->assertFileDoesNotExist($path);
    }
}
