<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Header
{
    /**
     * @param  array<string, MediaType>  $content
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $description = '',
        public bool $required = false,
        public bool $deprecated = false,
        public ?Schema $schema = null,
        public array $content = [],
        public ?string $style = null,
        public ?bool $explode = null,
        public array $extensions = [],
    ) {}
}
