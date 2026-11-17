<?php

namespace Utopia\Migration\Tests\Adapters;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\Compression;
use Appwrite\Models\Bucket;
use Appwrite\Services\Storage;

/**
 * Records the maximum file size each bucket create sends, and answers as an Appwrite instance
 * holding buckets to a limit does: a larger size is refused, and a bucket created without
 * one gets the limit.
 */
final class RecordingStorage extends Storage
{
    /** @var list<int|null> */
    public array $sizes = [];

    public function __construct(private readonly int $limit)
    {
        parent::__construct(new Client());
    }

    /**
     * @param array<mixed>|null $permissions
     * @param array<mixed>|null $allowedFileExtensions
     */
    public function createBucket(string $bucketId, string $name, ?array $permissions = null, ?bool $fileSecurity = null, ?bool $enabled = null, ?int $maximumFileSize = null, ?array $allowedFileExtensions = null, ?Compression $compression = null, ?bool $encryption = null, ?bool $antivirus = null, ?bool $transformations = null, ?string $description = null, ?bool $stripMetadata = null): Bucket
    {
        $this->sizes[] = $maximumFileSize;

        if ($maximumFileSize !== null && ($maximumFileSize < 1 || $maximumFileSize > $this->limit)) {
            throw new AppwriteException('Invalid `maximumFileSize` param: Value must be a valid range between 1 and ' . \number_format($this->limit), 400, 'general_argument_invalid');
        }

        if ($name === '') {
            throw new AppwriteException('Invalid `name` param: Value must be a valid string and at least 1 chars and no longer than 128 chars', 400, 'general_argument_invalid');
        }

        return Bucket::from([
            '$id' => $bucketId,
            'name' => $name,
            'maximumFileSize' => $maximumFileSize ?? $this->limit,
        ]);
    }
}
