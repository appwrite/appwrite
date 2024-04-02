<?php

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class Second extends Filter
{
    public function parse(array $content, string $model): array
    {
        if ($model === "namespace.method") {
            $content["second"] = true;
            unset($content["removed"]);
        }

        return $content;
    }
}
