<?php

use Appwrite\Utopia\Response;
use Swoole\Database\PDOProxy;
use Utopia\Http\Http;
use Utopia\Config\Config;

Http::get('/versions')
    ->desc('Get Version')
    ->groups(['home', 'web'])
    ->label('scope', 'public')
    ->inject('response')
    // ->inject('c')
    ->action(function (Response $response) {
        // $statement = $c->prepare('SELECT 1+1');
        // $statement->execute();
        // $res = $statement->fetchAll()[0][0];
        // \var_dump($res);

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
