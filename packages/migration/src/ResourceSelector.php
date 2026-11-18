<?php

namespace Utopia\Migration;

final readonly class ResourceSelector
{
    public function __construct(
        public string $resourceId,
        public string $resourceInternalId,
        public string $resourceType,
        public string $parentResourceId,
        public string $parentResourceInternalId,
        public string $parentResourceType,
    ) {
    }

    public function getScopeId(): string
    {
        return $this->parentResourceId ?: $this->resourceId;
    }

    public function getScopeType(): string
    {
        return $this->parentResourceType ?: $this->resourceType;
    }

}
