<?php

use Utopia\Config\Config;
use Utopia\Locale\Locale;

Locale::$exceptions = false;

$locales = Config::getParam('locale-codes', []);

foreach ($locales as $locale) {
    $code = $locale['code'];

    $path = __DIR__ . '/../config/locale/translations/' . $code . '.json';

    if (!\file_exists($path)) {
        // Only try 2-char prefix for locale variants (e.g., ar-ae -> ar), not standalone 3-char codes (e.g., ase)
        if (\str_contains($code, '-')) {
            $path = __DIR__ . '/../config/locale/translations/' . \substr($code, 0, 2) . '.json';
        }
        if (!\file_exists($path)) {
            $path = __DIR__ . '/../config/locale/translations/en.json'; // if no translation exists, use default from `en.json`
        }
    }

    Locale::setLanguageFromJSON($code, $path);
}
