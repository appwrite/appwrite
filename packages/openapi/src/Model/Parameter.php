<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Parameter
{
    /**
     * @param  array<string, MediaType>  $content
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $name,
        public ParameterLocation $location,
        public string $description = '',
        public bool $required = false,
        public bool $deprecated = false,
        public bool $allowEmptyValue = false,
        public ?Schema $schema = null,
        public array $content = [],
        public ?string $style = null,
        public ?bool $explode = null,
        public bool $allowReserved = false,
        public array $extensions = [],
    ) {}

    public function identity(): string
    {
        return $this->location->value . "\0" . $this->name;
    }
}
