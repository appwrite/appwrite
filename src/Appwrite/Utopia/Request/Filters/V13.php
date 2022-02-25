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
                $content = $this->convertNumToString($content, "min");
                $content = $this->convertNumToString($content, "max");
                $content = $this->convertNumToString($content, "default");
                break;
            case "database.createIntegerAttribute":
                $content = $this->convertNumToString($content, "min");
                $content = $this->convertNumToString($content, "max");
                $content = $this->convertNumToString($content, "default");
                break;
        }

        return $content;
    }

    private function convertNumToString($content, $value) {
        $content[$value] = (string) $content[$value];
        return $content;
    }
}
