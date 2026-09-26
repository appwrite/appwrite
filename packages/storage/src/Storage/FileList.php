<?php

declare(strict_types=1);

namespace Utopia\Storage;

/**
 * One page of a file listing. When `cursor` is not null, pass it back to
 * `Device::listFiles()` to fetch the next page.
 */
final readonly class FileList
{
    /**
     * @param  array<FileInfo>  $files
     */
    public function __construct(
        public array $files,
        public ?string $cursor = null,
    ) {}
}
