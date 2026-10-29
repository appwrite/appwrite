<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Operator;

class V28 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            'databases.updateDocument',
            'databases.upsertDocument',
            'databases.updateDocuments',
            'tablesDB.updateRow',
            'tablesDB.upsertRow',
            'tablesDB.updateRows',
            'documentsDB.updateDocument',
            'documentsDB.upsertDocument',
            'documentsDB.updateDocuments',
            'vectorsDB.updateDocument',
            'vectorsDB.upsertDocument',
            'vectorsDB.updateDocuments' => $this->parseDocument($content, 'data'),
            'databases.upsertDocuments',
            'documentsDB.upsertDocuments',
            'vectorsDB.upsertDocuments' => $this->parseDocuments($content, 'documents'),
            'tablesDB.upsertRows' => $this->parseDocuments($content, 'rows'),
            'databases.createOperations',
            'tablesDB.createOperations',
            'documentsDB.createOperations',
            'vectorsDB.createOperations' => $this->parseOperations($content),
            default => $content,
        };
    }

    protected function parseDocument(array $content, string $key): array
    {
        if (\array_key_exists($key, $content)) {
            $content[$key] = $this->wrapOperators($content[$key]);
        }

        return $content;
    }

    protected function parseDocuments(array $content, string $key): array
    {
        if (\is_array($content[$key] ?? null)) {
            $content[$key] = \array_map($this->wrapOperators(...), $content[$key]);
        }

        return $content;
    }

    protected function parseOperations(array $content): array
    {
        if (!\is_array($content['operations'] ?? null)) {
            return $content;
        }

        foreach ($content['operations'] as $index => $operation) {
            if (!\is_array($operation) || !\is_array($operation['data'] ?? null)) {
                continue;
            }

            $content['operations'][$index]['data'] = match ($operation['action'] ?? null) {
                'update', 'upsert' => $this->wrapOperators($operation['data']),
                'bulkUpdate' => $this->parseDocument($operation['data'], 'data'),
                'bulkUpsert' => \array_map($this->wrapOperators(...), $operation['data']),
                default => $operation['data'],
            };
        }

        return $content;
    }

    protected function wrapOperators(mixed $data): mixed
    {
        if (\is_string($data)) {
            $decoded = \json_decode($data, true);

            if (!\is_array($decoded)) {
                return $data;
            }

            $wrapped = $this->wrapOperators($decoded);

            return $wrapped === $decoded ? $data : $wrapped;
        }

        if (!\is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (!\is_string($value) || \str_starts_with((string) $key, '$')) {
                continue;
            }

            $operator = \json_decode($value, true);

            if (\is_array($operator) && \is_string($operator['method'] ?? null) && Operator::isMethod($operator['method'])) {
                $data[$key] = ['$operator' => $operator];
            }
        }

        return $data;
    }
}
