<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Discriminator
{
    /**
     * @param  array<string, string>  $mapping
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $propertyName,
        public array $mapping = [],
        public array $extensions = [],
    ) {}
}
