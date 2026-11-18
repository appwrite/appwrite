<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V17;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class V17Test extends TestCase
{
    public function testWidensRequiredBucketMimeType(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v17_' . \uniqid());
        $database->create();

        $database->createCollection(Collection::create(id: 'buckets'));
        $bucket = $database->createDocument('buckets', new Document(['$id' => 'bucket']));
        $bucketTable = "bucket_{$bucket->getSequence()}";
        $database->createCollection(Collection::create(
            id: $bucketTable,
            attributes: [Attribute::string(key: 'mimeType', size: 127, required: true)],
        ));

        $migration = new class ($database) extends V17 {
            public function __construct(Database $database)
            {
                $this->dbForProject = $database;
            }

            public function widenBucketMimeTypes(): void
            {
                $this->migrateBuckets();
            }
        };

        \ob_start();
        try {
            $migration->widenBucketMimeTypes();
        } finally {
            \ob_end_clean();
        }

        $mimeType = $database->getCollection($bucketTable)->attributes()[0];
        $this->assertSame('mimeType', $mimeType->key);
        $this->assertSame(255, $mimeType->size);
        $this->assertTrue($mimeType->required);
        $this->assertNull($mimeType->default);
    }
}
