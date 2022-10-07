<?php

global $cli;

use Utopia\CLI\Console;
use Utopia\Locale\Locale;
use Utopia\Validator\WhiteList;

$cli
    ->task('locales')
    ->desc('Find missing locales configuration')
    ->param('type', 'verbose', new WhiteList(['verbose', 'debug', 'json']), 'Style of result logging.', true)
    ->action(function ($type) {
        $languages = Locale::getLanguages();

        $mainLanguage = 'en';
        $mainTranslations = [];

        $mainLocale = new Locale($mainLanguage);
        foreach ($mainLocale->getTranslations() as $key => $value) {
            if(!empty($value)) {
                $mainTranslations[] = $key;
            }
        }

        $results = [];

        foreach ($languages as $language) {
            $locale = new Locale($language);
            $translations = $locale->getTranslations();
            $missing = [];
            foreach ($mainTranslations as $translation) {
                if(empty($translations[$translation])) {
                    $missing[] = $translation;
                }
            }

            if(\count($missing) <= 0) {
                $type !== 'json' && Console::success('Locale "' . $language . '" is fully translated.');
            } else {
                $type !== 'json' && Console::error('Locale "' . $language . '" is missing ' . \count($missing) . ' translations.');

                if($type === 'debug') {
                    foreach ($missing as $key) {
                        Console::log('"' . $key . '" missing in "' . $language . '".');
                    }
                } else if ($type === 'json') {
                    if(!(\array_key_exists($language, $results))) {
                        $results[$language] = [];
                    }

                    $results[$language] = $key;
                }
            }
        }

        $type !== 'json'  && Console::info('To get detailed information about missing translations, run the command with --type=debug parameter. Use --type=json to get JSON formatted results.');
        
        if($type === 'json') {
            Console::log(\json_encode($results));
        }
    
    });
