<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filter;

class V20 extends Filter
{
    // Convert 1.8 format to 1.7 format
    public function parse(array $content, string $model): array
    {
        return $content;
    }
}
