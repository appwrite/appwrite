<?php

declare(strict_types=1);

namespace Utopia\Storage;

/**
 * A multipart upload that was prepared but neither finalized nor aborted.
 */
final readonly class UploadInfo
{
    public function __construct(
        public string $path,
        public string $uploadId,
        public ?\DateTimeImmutable $initiatedAt = null,
    ) {}
}
