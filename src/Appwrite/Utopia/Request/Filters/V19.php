<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V19 extends Filter
{
    // Convert 1.6 params to 1.7
    public function parse(array $content, string $model): array
    {
        /*
        Uncomment with first request filter; current is just a copy of V18
        switch ($model) {
            case 'functions.create':
                $content['something'] = $content['somethingElse'] ?? "";
                unset($content['something']);
                break;
        }
        */

        return $content;
    }
}
