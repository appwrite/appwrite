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
            'users.createJWT' => $this->parseKeyword($content, 'sessionId', 'recent'),
            default => $content,
        };
    }

    /**
     * Rewrite a bare keyword to its parenthesised spelling.
     *
     * Keywords gained parentheses in 2.0.0 so they can never be mistaken for a
     * stored ID. Older clients still send the bare word, which by itself is a
     * perfectly valid ID — hence the rewrite happens here, per response
     * format, instead of the endpoint accepting both spellings forever.
     */
    protected function parseKeyword(array $content, string $key, string $keyword): array
    {
        if (($content[$key] ?? null) === $keyword) {
            $content[$key] = $keyword . '()';
        }

        return $content;
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
