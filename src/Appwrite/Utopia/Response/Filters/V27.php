<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9.6 Data format to 1.9.5 format
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

    /**
     * Reassemble the legacy "{databaseId}:{collectionId}" composite into the
     * single resourceId field that pre-1.9.6 SDKs expect, and surface the
     * legacy database-family resourceType (pre-1.9.6 versions stored the parent type in
     * resourceType, not parentResourceType). Strip the new fields so the
     * payload matches the pre-1.9.6 schema exactly.
     */
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

        return $content;
    }
}
