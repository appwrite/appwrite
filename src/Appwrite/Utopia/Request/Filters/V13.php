<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V13 extends Filter
{
    // Convert 0.12 params format to 0.13 format
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            // Replaced Types
            case 'database.createIntegerAttribute':
            case 'database.createFloatAttribute':
                $content = $this->convertStringToNum($content, 'min');
                $content = $this->convertStringToNum($content, 'max');
                $content = $this->convertStringToNum($content, 'default');
                break;
            case 'functions.createExecution':
                $content = $this->convertExecution($content);
        }

        return $content;
    }

    private function convertStringToNum($content, $value)
    {
        $content[$value] = is_null($content[$value]) ? null : (int) $content[$value];

        return $content;
    }

    private function convertExecution($content)
    {
        $content['async'] = true;

        return $content;
    }
}
