<?php

namespace Appwrite\Template;

use Utopia\Locale\Locale;

class EmailTemplate
{
    public static function translateTokens(string $content, Locale $locale): string
    {
        return preg_replace_callback('/\{\{\s*(emails\.[^}]+)\s*\}\}/', function ($matches) use ($locale) {
            return $locale->getText($matches[1], $matches[0]);
        }, $content) ?? $content;
    }
}
