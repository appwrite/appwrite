<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V25 extends Filter
{
    // Convert 1.9.3 params to 1.9.4
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'functions.createVariable':
            case 'sites.createVariable':
                $content = $this->fillVariableId($content);
                break;
        }

        return $content;
    }

    protected function fillVariableId(array $content): array
    {
        $content['variableId'] = $content['variableId'] ?? 'unique()';
        return $content;
    }
}
