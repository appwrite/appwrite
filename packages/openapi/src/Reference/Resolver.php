<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Reference;

interface Resolver
{
    public function resolve(Reference $reference, ResolutionContext $context): mixed;
}
