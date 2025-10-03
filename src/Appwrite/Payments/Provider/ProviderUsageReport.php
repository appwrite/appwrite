<?php

namespace Appwrite\Payments\Provider;

class ProviderUsageReport
{
    public function __construct(
        public readonly array $totals = [],
        public readonly array $details = [],
    ) {
    }
}


