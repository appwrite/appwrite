<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V12 extends Filter
{
    // Convert 0.11 params format to 0.12 format
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            // No IDs -> Custom IDs
            case 'account.create':
            case 'account.createMagicURLSession':
            case 'users.create':
                $content = $this->addId($content, 'userId');
                break;
            case 'functions.create':
                $content = $this->addId($content, 'functionId');
                break;
            case 'teams.create':
                $content = $this->addId($content, 'teamId');
                break;

                // Status integer -> boolean
            case 'users.updateStatus':
                $content = $this->convertStatus($content);
                break;

                // Deprecating order type
            case 'functions.listExecutions':
                $content = $this->removeOrderType($content);
                break;

                // The rest (more complex) formats
            case 'database.createDocument':
                $content = $this->addId($content, 'documentId');
                $content = $this->removeParentProperties($content);
                break;
            case 'database.listDocuments':
                $content = $this->removeOrderCast($content);
                $content = $this->convertOrder($content);
                $content = $this->convertQueries($content);
                break;
            case 'database.createCollection':
                $content = $this->addId($content, 'collectionId');
                $content = $this->removeRules($content);
                $content = $this->addCollectionPermissionLevel($content);
                break;
            case 'database.updateCollection':
                $content = $this->removeRules($content);
                $content = $this->addCollectionPermissionLevel($content);
                break;
        }

        return $content;
    }

    // New parameters

    protected function addId(array $content, string $key): array
    {
        $content[$key] = 'unique()';

        return $content;
    }

    protected function addCollectionPermissionLevel(array $content): array
    {
        $content['permission'] = 'document';

        return $content;
    }

    // Deprecated parameters

    protected function removeRules(array $content): array
    {
        unset($content['rules']);

        return $content;
    }

    protected function removeOrderType(array $content): array
    {
        unset($content['orderType']);

        return $content;
    }

    protected function removeOrderCast(array $content): array
    {
        unset($content['orderCast']);

        return $content;
    }

    protected function removeParentProperties(array $content): array
    {
        if (isset($content['parentDocument'])) {
            unset($content['parentDocument']);
        }
        if (isset($content['parentProperty'])) {
            unset($content['parentProperty']);
        }
        if (isset($content['parentPropertyType'])) {
            unset($content['parentPropertyType']);
        }

        return $content;
    }

    // Modified parameters

    protected function convertStatus(array $content): array
    {
        if (isset($content['status'])) {
            $content['status'] = $content['status'] === 2 ? false : true;
        }

        return $content;
    }

    protected function convertOrder(array $content): array
    {
        if (isset($content['orderField'])) {
            $content['orderAttributes'] = [$content['orderField']];
            unset($content['orderField']);
        }

        if (isset($content['orderType'])) {
            $content['orderTypes'] = [$content['orderType']];
            unset($content['orderType']);
        }

        return $content;
    }

    protected function convertQueries(array $content): array
    {
        $queries = [];

        if (! empty($content['filters'])) {
            foreach ($content['filters'] as $filter) {
                $operators = ['=' => 'equal', '!=' => 'notEqual', '>' => 'greater', '<' => 'lesser', '<=' => 'lesserEqual', '>=' => 'greaterEqual'];
                foreach ($operators as $operator => $operatorVerbose) {
                    if (\str_contains($filter, $operator)) {
                        $usedOperator = $operator;
                        break;
                    }
                }

                if (isset($usedOperator)) {
                    [ $attributeKey, $filterValue ] = \explode($usedOperator, $filter);

                    if ($filterValue === 'true' || $filterValue === 'false') {
                        // Let's keep it at true and false string, but without "" around
                        // No action needed
                    } else {
                        $filterValue = \is_numeric($filterValue) ? $filterValue : '"'.$filterValue.'"';
                    }

                    $query = $attributeKey.'.'.$operators[$usedOperator].'('.$filterValue.')';
                    \array_push($queries, $query);
                }
            }
        }

        // We cannot migrate search properly
        unset($content['search']);

        unset($content['filters']);
        $content['queries'] = $queries;

        return $content;
    }
}
