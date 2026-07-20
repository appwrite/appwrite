<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V27 extends Filter
{
    // Convert 1.9.5 params to 2.0.0
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            'migrations.createCSVImport',
            'migrations.createCSVExport',
            'migrations.createJSONImport',
            'migrations.createJSONExport' => $this->parseMigrationResource($content),
            default => $content,
        };
    }

    protected function parseMigrationResource(array $content): array
    {
        if (!isset($content['resourceId']) || !\is_string($content['resourceId'])) {
            return $content;
        }

        if (!\str_contains($content['resourceId'], ':')) {
            return $content;
        }

        [$databaseId, $collectionId] = \explode(':', $content['resourceId'], 2);
        $content['databaseId'] = $content['databaseId'] ?? $databaseId;
        $content['collectionId'] = $content['collectionId'] ?? $collectionId;
        unset($content['resourceId']);

        return $content;
    }
}
