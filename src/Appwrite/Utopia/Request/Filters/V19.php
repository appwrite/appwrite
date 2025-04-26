<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V19 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return $this->overrideDatabaseParams($content, $model);
    }

    // Database terminology change handling.
    protected function overrideDatabaseParams(array $content, string $model): array
    {
        if (!str_starts_with($model, 'databases.')) {
            return $content;
        }

        $map = [
            'collectionId' => 'tableId',
            'attributeId' => 'columnId',
            'attributes' => 'columns',
            'documentId' => 'rowId',
            'relatedCollectionId' => 'relatedTableId'
        ];

        foreach ($map as $oldKey => $newKey) {
            if (isset($content[$oldKey])) {
                $content[$newKey] = $content[$oldKey];
                unset($content[$oldKey]);
            }
        }

        return $content;
    }
}
