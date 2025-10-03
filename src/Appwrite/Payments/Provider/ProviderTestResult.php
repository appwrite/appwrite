<?php

namespace Appwrite\Payments\Provider;

class ProviderTestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message = '',
        public readonly array $metadata = [],
    ) {
    }
}


