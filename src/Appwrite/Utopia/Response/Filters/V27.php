<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9.6 data format to 1.9.5 format
class V27 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_MIGRATION => $this->parseMigration($content),
            Response::MODEL_MIGRATION_LIST => $this->handleList($content, 'migrations', fn ($item) => $this->parseMigration($item)),
            default => $content,
        };
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
        unset($content['destinationResourceType']);

        return $content;
    }
}
