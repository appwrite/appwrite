<?php

global $utopia, $response, $request, $layout, $projectDB;

use Utopia\App;
use Utopia\View;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Validator\UID;
use Appwrite\Storage\Storage;

App::init(function () use ($layout) {
    $layout
        ->setParam('description', 'Appwrite Console allows you to easily manage, monitor, and control your entire backend API and tools.')
        ->setParam('analytics', 'UA-26264668-5')
    ;
}, 'console');

App::shutdown(function () use ($response, $layout) {
    $header = new View(__DIR__.'/../../views/console/comps/header.phtml');
    $footer = new View(__DIR__.'/../../views/console/comps/footer.phtml');

    $footer
        ->setParam('home', App::getEnv('_APP_HOME', ''))
        ->setParam('version', Config::getParam('version'))
    ;

    $layout
        ->setParam('header', [$header])
        ->setParam('footer', [$footer])
    ;

    $response->send($layout->render());
}, 'console');

App::get('/error/:code')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->param('code', null, new \Utopia\Validator\Numeric(), 'Valid status code number', false)
    ->action(function ($code) use ($layout) {
        $page = new View(__DIR__.'/../../views/error.phtml');

        $page
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', APP_NAME.' - Error')
            ->setParam('body', $page);
    });

App::get('/console')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/index.phtml');

        $page
            ->setParam('home', App::getEnv('_APP_HOME', ''))
        ;

        $layout
            ->setParam('title', APP_NAME.' - Console')
            ->setParam('body', $page);
    });

App::get('/console/account')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/account/index.phtml');

        $cc = new View(__DIR__.'/../../views/console/forms/credit-card.phtml');

        $page
            ->setParam('cc', $cc)
        ;

        $layout
            ->setParam('title', 'Account - '.APP_NAME)
            ->setParam('body', $page);
    });

App::get('/console/notifications')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/v1/console/notifications/index.phtml');

        $layout
            ->setParam('title', APP_NAME.' - Notifications')
            ->setParam('body', $page);
    });

App::get('/console/home')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/home/index.phtml');

        $layout
            ->setParam('title', APP_NAME.' - Console')
            ->setParam('body', $page);
    });

App::get('/console/settings')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

        $page = new View(__DIR__.'/../../views/console/settings/index.phtml');

        $page
            ->setParam('customDomainsEnabled', ($target->isKnown() && !$target->isTest()))
            ->setParam('customDomainsTarget', $target->get())
        ;

        $layout
            ->setParam('title', APP_NAME.' - Settings')
            ->setParam('body', $page);
    });

App::get('/console/webhooks')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/webhooks/index.phtml');
        
        $page
            ->setParam('events', Config::getParam('events', []))
        ;
        
        $layout
            ->setParam('title', APP_NAME.' - Webhooks')
            ->setParam('body', $page);
    });

App::get('/console/keys')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $scopes = include __DIR__.'/../../../app/config/scopes.php';
        $page = new View(__DIR__.'/../../views/console/keys/index.phtml');

        $page->setParam('scopes', $scopes);

        $layout
            ->setParam('title', APP_NAME.' - API Keys')
            ->setParam('body', $page);
    });

App::get('/console/tasks')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/tasks/index.phtml');

        $layout
            ->setParam('title', APP_NAME.' - Tasks')
            ->setParam('body', $page);
    });

App::get('/console/database')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/database/index.phtml');

        $layout
            ->setParam('title', APP_NAME.' - Database')
            ->setParam('body', $page);
    });

App::get('/console/database/collection')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->param('id', '', function () { return new UID(); }, 'Collection unique ID.')
    ->action(function ($id) use ($response, $layout, $projectDB) {
        Authorization::disable();
        $collection = $projectDB->getDocument($id, false);
        Authorization::reset();

        if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $page = new View(__DIR__.'/../../views/console/database/collection.phtml');

        $page
            ->setParam('collection', $collection)
        ;
        
        $layout
            ->setParam('title', APP_NAME.' - Database Collection')
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
    ->param('collection', '', function () { return new UID(); }, 'Collection unique ID.')
    ->action(function ($collection) use ($layout, $projectDB) {
        Authorization::disable();
        $collection = $projectDB->getDocument($collection, false);
        Authorization::reset();

        if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
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
            ->setParam('title', APP_NAME.' - Database Document')
            ->setParam('body', $page);
    });

App::get('/console/storage')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($request, $layout) {
        $page = new View(__DIR__.'/../../views/console/storage/index.phtml');
        
        $page
            ->setParam('home', App::getEnv('_APP_HOME', 0))
            ->setParam('fileLimit', App::getEnv('_APP_STORAGE_LIMIT', 0))
            ->setParam('fileLimitHuman', Storage::human(App::getEnv('_APP_STORAGE_LIMIT', 0)))
        ;

        $layout
            ->setParam('title', APP_NAME.' - Storage')
            ->setParam('body', $page);
    });

App::get('/console/users')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/users/index.phtml');

        $page->setParam('providers', Config::getParam('providers'));

        $layout
            ->setParam('title', APP_NAME.' - Users')
            ->setParam('body', $page);
    });

App::get('/console/users/user')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/users/user.phtml');

        $layout
            ->setParam('title', APP_NAME.' - User')
            ->setParam('body', $page);
    });

App::get('/console/users/teams/team')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/console/users/team.phtml');

        $layout
            ->setParam('title', APP_NAME.' - Team')
            ->setParam('body', $page);
    });
