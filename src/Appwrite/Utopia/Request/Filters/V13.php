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
            case "database.createFloatAttribute":
                $content = $this->converStringToNum($content, "min");
                $content = $this->converStringToNum($content, "max");
                $content = $this->converStringToNum($content, "default");
                break;
            case "database.createIntegerAttribute":
                $content = $this->converStringToNum($content, "min");
                $content = $this->converStringToNum($content, "max");
                $content = $this->converStringToNum($content, "default");
                break;
            case "functions.createExecution":
                $content = $this->convertExecution($content);
        }

        return $content;
    }

    private function converStringToNum($content, $value) {
        $content[$value] = (int) $content[$value];
        return $content;
    }

    private function convertExecution($content) {
        $content['async'] = true;
        return $content;
    }
}
