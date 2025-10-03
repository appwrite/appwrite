<?php

namespace Appwrite\Payments\Provider;

class ProviderPortalSession
{
    public function __construct(
        public readonly string $url,
        public readonly array $metadata = [],
    ) {
    }
}


