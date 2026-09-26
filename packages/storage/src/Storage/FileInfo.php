<?php

declare(strict_types=1);

namespace Utopia\Storage;

final readonly class FileInfo
{
    public function __construct(
        public string $path,
        public int $size,
        public ?\DateTimeImmutable $modifiedAt = null,
        public ?string $etag = null,
    ) {}
}
