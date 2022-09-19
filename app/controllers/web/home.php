<?php

use Appwrite\Utopia\View;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;

App::init()
    ->groups(['home'])
    ->inject('layout')
    ->action(function (View $layout) {
        $header = new View(__DIR__ . '/../../views/home/comps/header.phtml');
        $footer = new View(__DIR__ . '/../../views/home/comps/footer.phtml');

        $footer
            ->setParam('version', App::getEnv('_APP_VERSION', 'UNKNOWN'))
        ;

        $layout
            ->setParam('title', APP_NAME)
            ->setParam('description', '')
            ->setParam('class', 'home')
            ->setParam('platforms', Config::getParam('platforms'))
            ->setParam('header', [$header])
            ->setParam('footer', [$footer])
        ;
    });

App::shutdown()
    ->groups(['home'])
    ->inject('response')
    ->inject('layout')
    ->action(function (Response $response, View $layout) {
        $response->html($layout->render());
    });

App::get('/')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('project')
    ->action(function (Response $response, Database $dbForConsole, Document $project) {

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Expires', 0)
            ->addHeader('Pragma', 'no-cache')
        ;

        if ('console' === $project->getId() || $project->isEmpty()) {
            $whitelistRoot = App::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled');

            if ($whitelistRoot !== 'disabled') {
                $count = $dbForConsole->count('users', [], 1);

                if ($count !== 0) {
                    return $response->redirect('/auth/signin');
                }
            }
        }

        $response->redirect('/auth/signup');
    });

App::get('/auth/signin')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/signin.phtml');

        $page
            ->setParam('root', App::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled'))
        ;

        $layout
            ->setParam('title', 'Sign In - ' . APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/signup')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/signup.phtml');

        $page
            ->setParam('root', App::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled'))
        ;

        $layout
            ->setParam('title', 'Sign Up - ' . APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/recovery')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/recovery.phtml');

        $page
            ->setParam('smtpEnabled', (!empty(App::getEnv('_APP_SMTP_HOST'))))
        ;

        $layout
            ->setParam('title', 'Password Recovery - ' . APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/confirm')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/confirm.phtml');

        $layout
            ->setParam('title', 'Account Confirmation - ' . APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/join')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/join.phtml');

        $layout
            ->setParam('title', 'Invitation - ' . APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/recovery/reset')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/recovery/reset.phtml');

        $layout
            ->setParam('title', 'Password Reset - ' . APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/oauth2/success')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/oauth2.phtml');

        $layout
            ->setParam('title', APP_NAME)
            ->setParam('body', $page)
            ->setParam('header', [])
            ->setParam('footer', [])
        ;
    });

App::get('/auth/magic-url')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/magicURL.phtml');

        $layout
            ->setParam('title', APP_NAME)
            ->setParam('body', $page)
            ->setParam('header', [])
            ->setParam('footer', [])
        ;
    });

App::get('/auth/oauth2/failure')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/home/auth/oauth2.phtml');

        $layout
            ->setParam('title', APP_NAME)
            ->setParam('body', $page)
            ->setParam('header', [])
            ->setParam('footer', [])
        ;
    });

App::get('/error/:code')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->param('code', null, new \Utopia\Validator\Numeric(), 'Valid status code number', false)
    ->inject('layout')
    ->action(function (int $code, View $layout) {

        $page = new View(__DIR__ . '/../../views/error.phtml');

        $page
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', 'Error' . ' - ' . APP_NAME)
            ->setParam('body', $page);
    });

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
