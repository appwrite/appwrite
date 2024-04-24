<?php

use Utopia\Config\Config;
use Utopia\Locale\Locale;

Locale::$exceptions = false;

$locales = Config::getParam('locale-codes', []);

foreach ($locales as $locale) {
    $code = $locale['code'];

    $path = __DIR__ . '/../config/locale/translations/' . $code . '.json';

    if (!\file_exists($path)) {
        $path = __DIR__ . '/../config/locale/translations/' . \substr($code, 0, 2) . '.json'; // if `ar-ae` doesn't exist, look for `ar`
        if (!\file_exists($path)) {
            $path = __DIR__ . '/../config/locale/translations/en.json'; // if none translation exists, use default from `en.json`
        }
    }

    Locale::setLanguageFromJSON($code, $path);
}