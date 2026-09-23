<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Encoding
{
    /**
     * @param  array<string, Header>  $headers
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public ?string $contentType = null,
        public array $headers = [],
        public ?string $style = null,
        public ?bool $explode = null,
        public bool $allowReserved = false,
        public array $extensions = [],
    ) {}
}
