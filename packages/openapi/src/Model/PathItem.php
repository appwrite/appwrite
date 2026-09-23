<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class PathItem
{
    /**
     * @param  array<string, Operation>  $operations  Indexed by lowercase HTTP method.
     * @param  list<Parameter>  $parameters
     * @param  list<Server>  $servers
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $path,
        public array $operations = [],
        public array $parameters = [],
        public string $summary = '',
        public string $description = '',
        public array $servers = [],
        public array $extensions = [],
    ) {}

    public function operation(HttpMethod $method): ?Operation
    {
        return $this->operations[$method->value] ?? null;
    }
}
