<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Utopia\Migration\Destinations\CSV as DestinationCSV;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\CSV as SourceCSV;
use Utopia\Migration\Transfer;

require_once __DIR__ . '/../../../app/init/constants.php';

final class CSVDatabaseTypesTest extends TestCase
{
    public function testCsvAllowedDatabaseTypesIncludesDocumentsDb(): void
    {
        $this->assertContains(DATABASE_TYPE_LEGACY, CSV_ALLOWED_DATABASE_TYPES);
        $this->assertContains(DATABASE_TYPE_TABLESDB, CSV_ALLOWED_DATABASE_TYPES);
        $this->assertContains(DATABASE_TYPE_DOCUMENTSDB, CSV_ALLOWED_DATABASE_TYPES);
        $this->assertContains(DATABASE_TYPE_VECTORSDB, CSV_ALLOWED_DATABASE_TYPES);
    }

    public function testDocumentsDbTransferResourcesAreSupportedByCsvSourceAndDestination(): void
    {
        $this->assertContains(Resource::TYPE_DOCUMENT, Transfer::GROUP_DOCUMENTSDB_RESOURCES);
        $this->assertContains(Resource::TYPE_DOCUMENT, SourceCSV::getSupportedResources());
        $this->assertContains(Resource::TYPE_DOCUMENT, DestinationCSV::getSupportedResources());
    }
}
