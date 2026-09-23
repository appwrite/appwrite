<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class SecurityRequirement
{
    /** @param array<string, list<string>> $schemes */
    public function __construct(public array $schemes) {}
}
