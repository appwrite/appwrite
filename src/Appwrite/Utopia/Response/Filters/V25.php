<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9.4 Data format to 1.9.3 format
class V25 extends Filter
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

        $content['resourceType'] = $content['parentResourceType'] ?? $content['resourceType'] ?? '';

        unset($content['resourceInternalId']);
        unset($content['parentResourceId']);
        unset($content['parentResourceInternalId']);
        unset($content['parentResourceType']);

        return $content;
    }
}
