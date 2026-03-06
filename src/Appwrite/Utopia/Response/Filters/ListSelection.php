<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filter;

class ListSelection extends Filter
{
    public function __construct(
        private array $selectQueries,
        private string $itemsKey
    ) {
    }

    public function parse(array $content, string $model): array
    {
        if (empty($this->selectQueries)) {
            return $content;
        }

        $selections = [];
        foreach ($this->selectQueries as $query) {
            if ($query->getAttribute() === '*') {
                return $content;
            }

            $selections[$query->getAttribute()] = true;
        }

        return $this->handleList($content, $this->itemsKey, function (array $item) use ($selections) {
            $filtered = [];
            foreach ($item as $key => $value) {
                if (isset($selections[$key]) || \str_starts_with($key, '$')) {
                    $filtered[$key] = $value;
                }
            }
            return $filtered;
        });
    }
}
