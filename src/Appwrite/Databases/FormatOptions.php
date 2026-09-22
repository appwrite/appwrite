<?php

namespace Appwrite\Databases;

use Utopia\Database\Document;

class FormatOptions
{
    public static function apply(Document $attribute): Document
    {
        $formatOptions = $attribute->getAttribute('formatOptions', []);
        if (\is_string($formatOptions)) {
            $formatOptions = \json_decode($formatOptions, true) ?? [];
        }
        if (!\is_array($formatOptions)) {
            return $attribute;
        }
        if (isset($formatOptions['min']) || isset($formatOptions['max'])) {
            $attribute->setAttribute('min', $formatOptions['min']);
            $attribute->setAttribute('max', $formatOptions['max']);
        }
        if (isset($formatOptions['elements'])) {
            $attribute->setAttribute('elements', $formatOptions['elements']);
        }

        return $attribute;
    }
}
