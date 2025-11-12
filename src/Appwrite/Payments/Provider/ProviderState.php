<?php

namespace Appwrite\Payments\Provider;

class ProviderState
{
    public function __construct(
        public readonly string $providerId,
        public readonly array $config = [],
        public readonly array $metadata = [],
    ) {
    }
}
