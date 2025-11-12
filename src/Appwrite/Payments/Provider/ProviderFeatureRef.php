<?php

namespace Appwrite\Payments\Provider;

class ProviderFeatureRef
{
    public function __construct(
        public readonly string $externalFeatureId,
        public readonly array $metadata = [],
    ) {
    }
}
