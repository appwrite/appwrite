<?php

namespace Appwrite\Payments\Provider;

class ProviderInvoice
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $subscriptionId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?int $createdAt = null,
        public readonly ?int $paidAt = null,
        public readonly ?string $invoiceUrl = null,
        public readonly array $metadata = [],
    ) {
    }
}
