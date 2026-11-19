<?php

namespace Utopia\Agents\Parts;

use Utopia\Agents\Part;

/**
 * An image, given either as raw bytes or as a URL the provider fetches.
 */
final readonly class Image implements Part
{
    public function __construct(
        public string $data = '',
        public string $mimeType = 'image/png',
        public ?string $url = null,
    ) {
        if ($data === '' && $url === null) {
            throw new \InvalidArgumentException('An image needs data or a URL');
        }
    }

    public function base64(): string
    {
        return base64_encode($this->data);
    }
}
