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
                $content = $this->convertStringToNum($content, "min");
                $content = $this->convertStringToNum($content, "max");
                $content = $this->convertStringToNum($content, "default");
                break;
            case "database.createIntegerAttribute":
                $content = $this->convertStringToNum($content, "min");
                $content = $this->convertStringToNum($content, "max");
                $content = $this->convertStringToNum($content, "default");
                break;
        }

        return $content;
    }

    private function convertStringToNum($content, $value) {
        $content[$value] = (float) $content[$value];
        return $content;
    }
}
