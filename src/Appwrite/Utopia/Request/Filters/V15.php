<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V15 extends Filter
{
    // Convert 0.15 params format to 0.16 format
    public function parse(array $content, string $model): array
    {
        switch ($model) {
        }

        return $content;
    }
}
