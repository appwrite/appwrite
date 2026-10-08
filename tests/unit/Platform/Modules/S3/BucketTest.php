<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\S3;

use Appwrite\Platform\Modules\S3\Http\S3\Create;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../app/init.php';

final class BucketTest extends TestCase
{
    public function testCreateBucketBuildsTheFilesCollectionFromTheConfiguredModels(): void
    {
        $created = null;

        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getAuthorization')->willReturn($authorization);
        $dbForProject->method('createDocument')->willReturnArgument(1);
        $dbForProject->method('getDocument')->willReturn(new Document(['$id' => 'photos', '$sequence' => '5']));
        $dbForProject->method('createCollection')->willReturnCallback(
            static function (Collection $collection) use (&$created): Collection {
                $created = $collection;

                return $collection;
            }
        );

        $action = new class () extends Create {
            public function provision(Database $dbForProject, string $bucketId): void
            {
                $this->createBucket($dbForProject, $bucketId);
            }
        };
        $action->provision($dbForProject, 'photos');

        $files = Config::getParam('collections', [])['buckets']['files'];

        $this->assertInstanceOf(Collection::class, $created);
        $this->assertSame('bucket_5', $created->getId());
        $this->assertFalse($created->documentSecurity());
        $this->assertEquals($files['attributes'], $created->attributes());
        $this->assertEquals($files['indexes'], $created->indexes());
    }
}
