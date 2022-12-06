<?php

use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Config\Config;

App::get('/versions')
    ->desc('Get Version')
    ->groups(['web', 'home'])
    ->label('scope', 'public')
    ->inject('response')
    ->action(function (Response $response) {
        $platforms = Config::getParam('platforms');

        $versions = [
            'server' => APP_VERSION_STABLE,
        ];

        foreach ($platforms as $platform) {
            $languages = $platform['languages'] ?? [];

            foreach ($languages as $key => $language) {
                if (isset($language['dev']) && $language['dev']) {
                    continue;
                }

                if (isset($language['enabled']) && !$language['enabled']) {
                    continue;
                }

                $platformKey = $platform['key'] ?? '';
                $languageKey = $language['key'] ?? '';
                $version = $language['version'] ?? '';
                $versions[$platformKey . '-' . $languageKey] = $version;
            }
        }

        $response->json($versions);
    });
