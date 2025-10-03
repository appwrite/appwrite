<?php

namespace Appwrite\Payments\Provider;

class ProviderPlanRef
{
    public function __construct(
        public readonly string $externalPlanId,
        public readonly array $metadata = [],
    ) {
    }
}


