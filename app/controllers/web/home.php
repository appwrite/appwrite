<?php

use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Http\Http;

Http::get('/versions')
    ->desc('Get Version')
    ->groups(['home', 'web'])
    ->label('scope', 'public')
    ->inject('response')
    ->action(function (Response $response) {
        $platforms = Config::getParam('platforms');

        $versions = [
            'server' => APP_VERSION_STABLE,
        ];

        foreach ($platforms as $platform) {
            $languages = $platform['sdks'] ?? [];

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
