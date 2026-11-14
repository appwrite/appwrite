<?php

namespace Utopia\Migration\Tests\Destinations;

use Appwrite\AppwriteException;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Storage\Bucket;
use Utopia\Migration\Tests\Adapters\RecordingStorage;
use Utopia\Migration\Warning;

/**
 * A bucket keeps the maximum file size it was created with when the limit of its project
 * drops, by a plan change or on another instance, so a copy of it can ask for more than its
 * destination allows. Refusing it failed the bucket and every file in it.
 */
final class AppwriteBucketFileSizeTest extends TestCase
{
    /** Cloud's Free plan */
    private const int LIMIT = 50_000_000;

    public function testSizeWithinTheLimitIsKept(): void
    {
        $storage = new RecordingStorage(self::LIMIT);
        $destination = $this->destination($storage);

        $resource = $destination->importFileResource($this->bucket(30_000_000));

        $this->assertSame([30_000_000], $storage->sizes);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertSame([], $this->warnings($destination));
    }

    public function testSizeAboveTheLimitIsImportedWithTheDestinationsLimit(): void
    {
        // Created on Pro, which allows 5GB, in a project that is now on Free
        $storage = new RecordingStorage(self::LIMIT);
        $destination = $this->destination($storage);

        $resource = $destination->importFileResource($this->bucket(5_000_000_000));

        // Refused with the size, then created without one, which takes the destination's limit
        $this->assertSame([5_000_000_000, null], $storage->sizes);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertSame('bucket', $resource->getId());
        $this->assertCount(1, $this->warnings($destination));
        $this->assertSame('bucket', $this->warnings($destination)[0]->getResourceId());
        $this->assertStringContainsString('5000000000', $this->warnings($destination)[0]->getMessage());
        $this->assertStringContainsString('50000000', $this->warnings($destination)[0]->getMessage());
    }

    public function testAnotherRefusalIsReportedAsItWas(): void
    {
        // Leaving the size out does not help a bucket refused for something else: the
        // failure reported is the one the bucket was refused for.
        $storage = new RecordingStorage(self::LIMIT);
        $destination = $this->destination($storage);

        try {
            $destination->importFileResource($this->bucket(30_000_000, name: ''));
            $this->fail('A bucket without a name must be refused');
        } catch (AppwriteException $error) {
            $this->assertStringContainsString('`name`', $error->getMessage());
        }

        $this->assertSame([], $this->warnings($destination));
    }

    private function bucket(int $size, string $name = 'Bucket'): Bucket
    {
        return new Bucket('bucket', $name, maxFileSize: $size);
    }

    /**
     * @return list<Warning>
     */
    private function warnings(AppwriteDestination $destination): array
    {
        $warnings = [];
        foreach ($destination->getWarnings() as $warning) {
            $this->assertInstanceOf(Warning::class, $warning);
            $warnings[] = $warning;
        }

        return $warnings;
    }

    private function destination(RecordingStorage $storage): AppwriteDestination
    {
        return new RecordingDestination(storage: $storage);
    }
}
