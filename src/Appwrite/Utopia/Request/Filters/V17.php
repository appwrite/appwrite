<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V17 extends Filter
{
    // Convert 1.4 params to 1.5
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'account.updateRecovery':
                unset($content['passwordAgain']);
                break;
        }
        return $content;
    }
}
