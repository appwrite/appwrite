<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class OAuthFlow
{
    /** @param array<string, string> $scopes */
    public function __construct(
        public ?string $authorizationUrl = null,
        public ?string $tokenUrl = null,
        public ?string $refreshUrl = null,
        public array $scopes = [],
    ) {}
}
