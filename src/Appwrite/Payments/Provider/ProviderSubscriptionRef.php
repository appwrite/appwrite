<?php

namespace Appwrite\Payments\Provider;

class ProviderSubscriptionRef
{
    public function __construct(
        public readonly string $externalSubscriptionId,
        public readonly array $metadata = [],
    ) {
    }
}
