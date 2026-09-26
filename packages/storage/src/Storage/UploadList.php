<?php

declare(strict_types=1);

namespace Utopia\Storage;

/**
 * One page of multipart uploads in progress. When `cursor` is not null, pass
 * it back to `S3::listUploads()` to fetch the next page.
 */
final readonly class UploadList
{
    /**
     * @param  array<UploadInfo>  $uploads
     */
    public function __construct(
        public array $uploads,
        public ?string $cursor = null,
    ) {}
}
