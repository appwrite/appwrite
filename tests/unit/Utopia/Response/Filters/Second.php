<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filter;

class Second extends Filter
{
    public function parse(array $content, string $model): array
    {
        if ($model === "test") {
            $content["second"] = true;
            unset($content["removed"]);
        }

        return $content;
    }
}
