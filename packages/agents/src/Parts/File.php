<?php

namespace Utopia\Agents\Parts;

use Utopia\Agents\Part;

/**
 * A document such as a PDF, given either as raw bytes or as a URL.
 */
final readonly class File implements Part
{
    public function __construct(
        public string $data = '',
        public string $mimeType = 'application/pdf',
        public ?string $name = null,
        public ?string $url = null,
    ) {
        if ($data === '' && $url === null) {
            throw new \InvalidArgumentException('A file needs data or a URL');
        }
    }

    public function base64(): string
    {
        return base64_encode($this->data);
    }
}
