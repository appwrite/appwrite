<?php

namespace Utopia\Migration;

class Warning
{
    public string $resourceName;

    public string $resourceGroup;

    public string $resourceId;

    public string $message;

    public function __construct(string $resourceName, string $resourceGroup, string $message, string $resourceId = '')
    {
        $this->resourceName = $resourceName;
        $this->resourceId = $resourceId;
        $this->resourceGroup = $resourceGroup;
        $this->message = $message;
    }

    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    public function getResourceGroup(): string
    {
        return $this->resourceGroup;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
