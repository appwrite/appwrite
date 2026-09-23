<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class MediaType
{
    /**
     * @param  array<string, Example>  $examples
     * @param  array<string, Encoding>  $encoding
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public ?Schema $schema = null,
        public mixed $example = null,
        public array $examples = [],
        public array $encoding = [],
        public array $extensions = [],
    ) {}
}
