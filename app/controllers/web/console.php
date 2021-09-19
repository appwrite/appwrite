<?php

use Utopia\App;
use Utopia\View;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Validator\UID;
use Utopia\Storage\Storage;

App::init(function ($layout) {
    /** @var Utopia\View $layout */

    $layout
        ->setParam('description', 'Appwrite Console allows you to easily manage, monitor, and control your entire backend API and tools.')
        ->setParam('analytics', 'UA-26264668-5')
    ;
}, ['layout'], 'console');

App::shutdown(function ($response, $layout, $locale) {
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\View $layout */
    /** @var Utopia\Locale\Locale $locale */

    $header = new View(__DIR__.'/../../views/console/comps/header.phtml');
    $footer = new View(__DIR__.'/../../views/console/comps/footer.phtml');

    $header
        ->setParam('locale', $locale);

    $footer
        ->setParam('locale', $locale)
        ->setParam('home', App::getEnv('_APP_HOME', ''))
        ->setParam('version', App::getEnv('_APP_VERSION', 'UNKNOWN'))
    ;

    $layout
        ->setParam('header', [$header])
        ->setParam('footer', [$footer])
    ;

    $response->html($layout->render());
}, ['response', 'layout', 'locale'], 'console');

App::get('/error/:code')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->param('code', null, new \Utopia\Validator\Numeric(), 'Valid status code number', false)
    ->inject('layout')
    ->action(function ($code, $layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/error.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.error'))
            ->setParam('body', $page);
    });

App::get('/console')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/index.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('home', App::getEnv('_APP_HOME', ''))
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.console'))
            ->setParam('body', $page);
    });

App::get('/console/account')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/account/index.phtml');

        $cc = new View(__DIR__.'/../../views/console/forms/credit-card.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('cc', $cc)
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.account'))
            ->setParam('body', $page);
    });

App::get('/console/notifications')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/v1/console/notifications/index.phtml');

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.notifications'))
            ->setParam('body', $page);
    });

App::get('/console/home')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/home/index.phtml');
        $page
            ->setParam('locale', $locale)
            ->setParam('usageStatsEnabled',App::getEnv('_APP_USAGE_STATS','enabled') == 'enabled');
        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.console'))
            ->setParam('body', $page);
    });

App::get('/console/settings')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

        $page = new View(__DIR__.'/../../views/console/settings/index.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('customDomainsEnabled', ($target->isKnown() && !$target->isTest()))
            ->setParam('customDomainsTarget', $target->get())
            ->setParam('smtpEnabled', (!empty(App::getEnv('_APP_SMTP_HOST'))))
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.settings'))
            ->setParam('body', $page);
    });

App::get('/console/webhooks')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/webhooks/index.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('events', Config::getParam('events', []))
        ;
        
        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.webhooks'))
            ->setParam('body', $page);
    });

App::get('/console/keys')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $scopes = array_keys(Config::getParam('scopes'));
        $page = new View(__DIR__.'/../../views/console/keys/index.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('scopes', $scopes);

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.api-keys'))
            ->setParam('body', $page);
    });

App::get('/console/tasks')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/tasks/index.phtml');

        $page
            ->setParam("locale", $locale);

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.tasks'))
            ->setParam('body', $page);
    });

App::get('/console/database')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/database/index.phtml');

        $page
            ->setParam("locale", $locale);

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.database'))
            ->setParam('body', $page);
    });

App::get('/console/database/collection')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->param('id', '', new UID(), 'Collection unique ID.')
    ->inject('response')
    ->inject('layout')
    ->inject('projectDB')
    ->inject('locale')
    ->action(function ($id, $response, $layout, $projectDB, $locale) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\View $layout */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Locale\Locale $locale */

        Authorization::disable();
        $collection = $projectDB->getDocument($id, false);
        Authorization::reset();

        if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception($locale->getText('exceptions.collection-not-found'), 404);
        }

        $page = new View(__DIR__.'/../../views/console/database/collection.phtml');

        $page
            ->setParam('collection', $collection)
        ;
        
        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.database-collection'))
            ->setParam('body', $page)
        ;

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Expires', 0)
            ->addHeader('Pragma', 'no-cache')
        ;
    });

App::get('/console/database/document')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->param('collection', '', new UID(), 'Collection unique ID.')
    ->inject('layout')
    ->inject('projectDB')
    ->inject('locale')
    ->action(function ($collection, $layout, $projectDB, $locale) {
        /** @var Utopia\View $layout */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Locale\Locale $locale */

        Authorization::disable();
        $collection = $projectDB->getDocument($collection, false);
        Authorization::reset();

        if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception($locale->getText('exceptions.collection-not-found'), 404);
        }

        $page = new View(__DIR__.'/../../views/console/database/document.phtml');
        $searchFiles = new View(__DIR__.'/../../views/console/database/search/files.phtml');
        $searchDocuments = new View(__DIR__.'/../../views/console/database/search/documents.phtml');

        $page
            ->setParam('db', $projectDB)
            ->setParam('collection', $collection)
            ->setParam('searchFiles', $searchFiles)
            ->setParam('searchDocuments', $searchDocuments)
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.database-document'))
            ->setParam('body', $page);
    });

App::get('/console/storage')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/storage/index.phtml');
        
        $page
            ->setParam('locale', $locale)
            ->setParam('home', App::getEnv('_APP_HOME', 0))
            ->setParam('fileLimit', App::getEnv('_APP_STORAGE_LIMIT', 0))
            ->setParam('fileLimitHuman', Storage::human(App::getEnv('_APP_STORAGE_LIMIT', 0)))
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.storage'))
            ->setParam('body', $page);
    });

App::get('/console/users')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/users/index.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('auth', Config::getParam('auth'))
            ->setParam('providers', Config::getParam('providers'))
            ->setParam('smtpEnabled', (!empty(App::getEnv('_APP_SMTP_HOST'))))
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.users'))
            ->setParam('body', $page);
    });

App::get('/console/users/user')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/users/user.phtml');

        $page
            ->setParam('locale', $locale);

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.user'))
            ->setParam('body', $page);
    });

App::get('/console/users/teams/team')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/users/team.phtml');

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.team'))
            ->setParam('body', $page);
    });

App::get('/console/functions')
    ->groups(['web', 'console'])
    ->desc('Platform console project functions')
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/functions/index.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('runtimes', Config::getParam('runtimes'))
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.functions'))
            ->setParam('body', $page);
    });

App::get('/console/functions/function')
    ->groups(['web', 'console'])
    ->desc('Platform console project function')
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->inject('locale')
    ->action(function ($layout, $locale) {
        /** @var Utopia\View $layout */
        /** @var Utopia\Locale\Locale $locale */

        $page = new View(__DIR__.'/../../views/console/functions/function.phtml');

        $page
            ->setParam('locale', $locale)
            ->setParam('events', Config::getParam('events', []))
            ->setParam('fileLimit', App::getEnv('_APP_STORAGE_LIMIT', 0))
            ->setParam('fileLimitHuman', Storage::human(App::getEnv('_APP_STORAGE_LIMIT', 0)))
            ->setParam('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900))
            ->setParam('usageStatsEnabled',App::getEnv('_APP_USAGE_STATS','enabled') == 'enabled');
        ;

        $layout
            ->setParam('title', APP_NAME.' - ' . $locale->getText('titles.function'))
            ->setParam('body', $page);
    });

App::get('/console/version')
    ->groups(['web', 'console'])
    ->desc('Check for new version')
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('response')
    ->inject('locale')
    ->action(function ($response, $locale) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */

        try {
            $version = \json_decode(@\file_get_contents(App::getEnv('_APP_HOME', 'http://localhost').'/v1/health/version'), true);
            
            if ($version && isset($version['version'])) {
                return $response->json(['version' => $version['version']]);
            } else {
                throw new Exception($locale->getText('exceptions.version-check-failed'), 500);
            }
        } catch (\Throwable $th) {
            throw new Exception($locale->getText('exceptions.version-check-failed'), 500);
        }
    });