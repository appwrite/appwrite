<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Operation
{
    /**
     * @param  list<string>  $tags
     * @param  list<Parameter>  $parameters
     * @param  array<int|string, Response>  $responses
     * @param  list<SecurityRequirement>  $security
     * @param  list<Server>  $servers
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $id,
        public HttpMethod $method,
        public string $path,
        public array $tags = [],
        public string $summary = '',
        public string $description = '',
        public bool $deprecated = false,
        public array $parameters = [],
        public ?RequestBody $requestBody = null,
        public array $responses = [],
        public array $security = [],
        public array $servers = [],
        public ?ExternalDocumentation $externalDocumentation = null,
        public array $extensions = [],
    ) {}

    /**
     * Names present in any security alternative, in first-seen order.
     *
     * This is not a combined authentication requirement: alternatives and
     * their OAuth scopes remain separate in $security.
     *
     * @return list<string>
     */
    public function acceptedSecuritySchemeNames(): array
    {
        $names = [];
        foreach ($this->security as $requirement) {
            foreach (array_keys($requirement->schemes) as $name) {
                $name = (string) $name;
                if (! \in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Names present in every security alternative, in first-seen order.
     *
     * No security requirements or an anonymous alternative yields no names.
     * This does not imply that OAuth scopes are identical across alternatives.
     *
     * @return list<string>
     */
    public function requiredSecuritySchemeNames(): array
    {
        $names = array_map(static fn(string|int $name): string => (string) $name, array_keys($this->security[0]->schemes ?? []));
        foreach ($this->security as $requirement) {
            $names = array_values(array_intersect($names, array_keys($requirement->schemes)));
        }

        return $names;
    }
}
