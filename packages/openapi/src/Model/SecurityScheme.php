<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class SecurityScheme
{
    /**
     * @param  array<string, OAuthFlow>  $flows
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public SecuritySchemeType $type,
        public string $description = '',
        public ?string $name = null,
        public ?ParameterLocation $location = null,
        public ?string $scheme = null,
        public ?string $bearerFormat = null,
        public array $flows = [],
        public ?string $openIdConnectUrl = null,
        public array $extensions = [],
    ) {}
}
