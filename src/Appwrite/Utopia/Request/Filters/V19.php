<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V19 extends Filter
{
    // Map old params to new
    private const PARAMS_MAP = [
        'documentId' => 'rowId',
        'attributes' => 'columns',
        'collectionId' => 'tableId',
        'attributeId' => 'columnId',
        '$collectionId' => '$tableId',
        'relatedCollection' => 'relatedTable',
        'relatedCollectionId' => 'relatedTableId',
    ];

    // Convert 1.6 params to 1.7
    public function parse(array $content, string $model): array
    {
        $content = $this->overrideDatabaseParams($content, $model);

        return $content;
    }

    protected function overrideDatabaseParams(array $content, string $model): array
    {
        if (!str_starts_with($model, 'databases.')) {
            return $content;
        }

        $intersect = array_intersect_key(self::PARAMS_MAP, $content);

        foreach ($intersect as $oldKey => $newKey) {
            $content[$newKey] = $content[$oldKey];
            unset($content[$oldKey]);
        }

        return $content;
    }
}
