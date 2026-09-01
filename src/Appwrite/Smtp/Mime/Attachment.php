<?php

declare(strict_types=1);

namespace Appwrite\Smtp\Mime;

final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $contentId,
        public string $disposition,
        public string $content,
    ) {
    }
}
