<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class DatabaseAliases extends Filter
{
    // Map old params to new
    private const PARAMS_MAP = [
        'documentId' => 'rowId',
        'attributes' => 'columns',
        'collectionId' => 'tableId',
        'attributeId' => 'columnId',
        'relatedCollection' => 'relatedTable',
        'relatedCollectionId' => 'relatedTableId',
    ];

    public function parse(array $content, string $model): array
    {
        return $this->overrideDatabaseParams($content, $model);
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
