<?php

namespace Appwrite\Payments\Provider;

class ProviderProrationPreview
{
    public function __construct(
        public readonly int $amountDue,
        public readonly int $prorationAmount,
        public readonly string $currency,
        public readonly ?int $nextBillingDate = null,
        public readonly array $metadata = [],
    ) {
    }
}
