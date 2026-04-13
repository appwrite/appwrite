<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filter;

class First extends Filter
{
    public function parse(array $content, string $model): array
    {
        if ($model === 'test') {
            $content['first'] = true;
            $content['second'] = false;
            $content['removed'] = true;
        }

        return $content;
    }
}
