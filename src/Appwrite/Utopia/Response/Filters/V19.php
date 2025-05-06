<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V19 extends Filter
{
    private const DATABASE_MAPPINGS = [
        'table' => 'collection',
        'tables' => 'collections',
        '$tableId' => '$collectionId',
        'tablesTotal' => 'collectionsTotal',
        'relatedTable' => 'relatedCollection',
        'relatedTableId' => 'relatedCollectionId',

        'column' => 'attribute',
        'columns' => 'attributes',
        'columnsTotal' => 'attributesTotal',

        'row' => 'document',
        'rows' => 'documents',
        'rowsTotal' => 'documentsTotal'
    ];

    // Convert 1.7 Data format to 1.6 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        return match ($model) {
            Response::MODEL_ROW,
            Response::MODEL_TABLE,
            Response::MODEL_COLUMN,
            Response::MODEL_ROW_LIST,
            Response::MODEL_TABLE_LIST,
            Response::MODEL_COLUMN_LIST,
            Response::MODEL_USAGE_TABLE,
            Response::MODEL_USAGE_DATABASE,
            Response::MODEL_USAGE_DATABASES,
            Response::MODEL_COLUMN_RELATIONSHIP => $this->handleDBTerminology($model, $content),

            Response::MODEL_FUNCTION => $this->parseFunction($content),
            Response::MODEL_FUNCTION_LIST => $this->handleList($content, 'functions', fn ($item) => $this->parseFunction($item)),
            default => $parsedResponse,
        };
    }

    protected function parseFunction(array $content): array
    {
        $content['deployment'] = $content['deploymentId'] ?? '';
        unset($content['deploymentId']);
        return $content;
    }

    protected function handleDBTerminology(string $model, array $content): array
    {
        $isListModel = match ($model) {
            Response::MODEL_ROW_LIST,
            Response::MODEL_TABLE_LIST,
            Response::MODEL_COLUMN_LIST => true,

            default => false
        };

        if ($isListModel) {
            foreach (self::DATABASE_MAPPINGS as $oldKey => $newKey) {
                if (isset($content[$oldKey])) {
                    $content[$newKey] = array_map(fn ($item) => $this->remapKeys($item), $content[$oldKey]);
                    unset($content[$oldKey]);
                }
            }
        } else {
            $content = $this->remapKeysRecursive($content);
        }

        return $content;
    }

    private function remapKeys(array $data): array
    {
        foreach (self::DATABASE_MAPPINGS as $old => $new) {
            if (isset($data[$old])) {
                $data[$new] = $data[$old];
                unset($data[$old]);
            }
        }
        return $data;
    }

    private function remapKeysRecursive(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $newKey = self::DATABASE_MAPPINGS[$key] ?? $key;
            $result[$newKey] = \is_array($value) ? $this->remapKeysRecursive($value) : $value;
        }
        return $result;
    }
}
