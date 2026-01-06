<?php

namespace Appwrite\Payments\Provider;

class ProviderCheckoutSession
{
    public function __construct(
        public readonly string $url,
        public readonly array $metadata = [],
    ) {
    }
}
