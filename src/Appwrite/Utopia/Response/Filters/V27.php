<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 2.0.0 Data format to 1.9.5 format
class V27 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_EXECUTION => $this->parseExecution($content),
            Response::MODEL_EXECUTION_LIST => $this->handleList($content, 'executions', fn ($item) => $this->parseExecution($item)),
            Response::MODEL_MIGRATION => $this->parseMigration($content),
            Response::MODEL_MIGRATION_LIST => $this->handleList($content, 'migrations', fn ($item) => $this->parseMigration($item)),
            default => $content,
        };
    }

    protected function parseExecution(array $content): array
    {
        if (isset($content['resourceId'])) {
            $content['functionId'] = $content['resourceId'];
            unset($content['resourceId']);
        }

        unset($content['resourceType']);

        return $content;
    }

    protected function parseMigration(array $content): array
    {
        $parentResourceId = $content['parentResourceId'] ?? '';
        $resourceId = $content['resourceId'] ?? '';

        if ($parentResourceId !== '' && $resourceId !== '') {
            $content['resourceId'] = $parentResourceId . ':' . $resourceId;
        }

        $parentResourceType = $content['parentResourceType'] ?? '';
        if ($parentResourceType !== '') {
            $content['resourceType'] = $parentResourceType;
        }

        unset($content['resourceInternalId']);
        unset($content['parentResourceId']);
        unset($content['parentResourceInternalId']);
        unset($content['parentResourceType']);
        unset($content['destinationResourceId']);
        unset($content['destinationResourceInternalId']);
        unset($content['destinationResourceType']);

        return $content;
    }
}
