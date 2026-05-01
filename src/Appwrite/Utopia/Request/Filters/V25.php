<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V25 extends Filter
{
    // Convert 1.9.3 params to 1.9.4
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'migrations.createCSVImport':
            case 'migrations.createCSVExport':
            case 'migrations.createJSONImport':
            case 'migrations.createJSONExport':
                $content = $this->parseMigrationResource($content);
                break;
        }

        return $content;
    }

    protected function parseMigrationResource(array $content): array
    {
        if (!isset($content['resourceId']) || !\is_string($content['resourceId'])) {
            return $content;
        }

        if (\str_contains($content['resourceId'], ':')) {
            [$databaseId, $collectionId] = \explode(':', $content['resourceId'], 2);
            $content['databaseId'] = $content['databaseId'] ?? $databaseId;
            $content['collectionId'] = $content['collectionId'] ?? $collectionId;
        }

        unset($content['resourceId']);

        return $content;
    }
}
