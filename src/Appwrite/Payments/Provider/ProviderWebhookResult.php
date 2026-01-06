<?php

namespace Appwrite\Payments\Provider;

class ProviderWebhookResult
{
    public function __construct(
        public readonly string $status,
        public readonly array $changes = [],
    ) {
    }
}
