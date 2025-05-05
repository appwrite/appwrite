<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V19 extends Filter
{
    // Convert 1.6 params to 1.7
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            'databases.createRelationshipColumn' => $this->convertV16RelationshipParams($content),
            default => $content
        };
    }

    protected function convertV16RelationshipParams(array $content): array
    {
        if (isset($content['relatedCollectionId'])) {
            $content['relatedTableId'] = $content['relatedCollectionId'];
            unset($content['relatedCollectionId']);
        }

        return $content;
    }
}
