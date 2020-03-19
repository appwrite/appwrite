#!/bin/env php
<?php

require_once __DIR__.'/../init.php';

global $register, $projectDB, $console, $providers, $request;

use Database\Database;
use Database\Document;
use Database\Validator\Authorization;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;

$cli = new CLI();
$db = $register->get('db');

$callbacks = [
    '0.4.0' => function() {
        Console::log('I got nothing to do.');
    },
    '0.5.0' => function($project) use ($db, $projectDB, $requset) {

        Console::info('Upgrading project: '.$project->getId());

        // Update all documents $uid -> $id

        $limit = 30;
        $sum = 30;
        $offset = 0;

        while ($sum >= 30) {
            $all = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => '$uid',
                'orderType' => 'DESC',
                'orderCast' => 'string',
            ]);

            $sum = count($all);
            
            Console::success('Fetched '.$sum.' (offset: '.$offset.' / limit: '.$limit.') documents from a total of '.$projectDB->getSum());
            
            foreach($all as $document) {
                $document = fixDocument($document);

                if(empty($document->getId())) {
                    throw new Exception('Missing ID');
                }

                try {
                    $new = $projectDB->overwriteDocument($document->getArrayCopy());
                    Console::success('Updated document succefully');
                } catch (\Throwable $th) {
                    Console::error('Failed to update document: '.$th->getMessage());
                    continue;
                }

                if($new->getId() !== $document->getId()) {
                    throw new Exception('Duplication Error');
                }
            }

            $offset = $offset + $limit;
        }

        $schema = $requset->getServer('_APP_DB_SCHEMA');
        
        try {
            $statement = $db->prepare("
                ALTER TABLE `{$schema}`.`app_{$project->getId()}.audit.audit` DROP COLUMN IF EXISTS `userType`;
                ALTER TABLE `{$schema}`.`app_{$project->getId()}.audit.audit` DROP INDEX IF EXISTS `index_1`;
                ALTER TABLE `{$schema}`.`app_{$project->getId()}.audit.audit` ADD INDEX IF NOT EXISTS `index_1` (`userId` ASC);
            ");

            $statement->closeCursor();

            $statement->execute();
        }
        catch (\Exception $e) {
            Console::error('Failed to alter table for project: '.$project->getId().' with message: '.$e->getMessage().'/');
        }
    },
];

function fixDocument(Document $document) {
    global $providers;

    if($document->getAttribute('$collection') === Database::SYSTEM_COLLECTION_PROJECTS){
        foreach($providers as $key => $provider) {
            if(!empty($document->getAttribute('usersOauth'.ucfirst($key).'Appid'))) {
                $document
                    ->setAttribute('usersOauth2'.ucfirst($key).'Appid', $document->getAttribute('usersOauth'.ucfirst($key).'Appid', ''))
                    ->removeAttribute('usersOauth'.ucfirst($key).'Appid')
                ;
            }

            if(!empty($document->getAttribute('usersOauth'.ucfirst($key).'Secret'))) {
                $document
                    ->setAttribute('usersOauth2'.ucfirst($key).'Secret', $document->getAttribute('usersOauth'.ucfirst($key).'Secret', ''))
                    ->removeAttribute('usersOauth'.ucfirst($key).'Secret')
                ;
            }
        }
    }

    if($document->getAttribute('$collection') === Database::SYSTEM_COLLECTION_USERS) {
        foreach($providers as $key => $provider) {
            if(!empty($document->getAttribute('oauth'.ucfirst($key)))) {
                $document
                    ->setAttribute('oauth2'.ucfirst($key), $document->getAttribute('oauth'.ucfirst($key), ''))
                    ->removeAttribute('oauth'.ucfirst($key))
                ;
            }

            if(!empty($document->getAttribute('oauth'.ucfirst($key).'AccessToken'))) {
                $document
                    ->setAttribute('oauth2'.ucfirst($key).'AccessToken', $document->getAttribute('oauth'.ucfirst($key).'AccessToken', ''))
                    ->removeAttribute('oauth'.ucfirst($key).'AccessToken')
                ;
            }
        }
    
        if($document->getAttribute('confirm', null) !== null) {
            $document
                ->setAttribute('emailVerification', $document->getAttribute('confirm', $document->getAttribute('emailVerification', false)))
                ->removeAttribute('confirm')
            ;
        }
    }

    if($document->getAttribute('$collection') === Database::SYSTEM_COLLECTION_PLATFORMS) {
        if($document->getAttribute('url', null) !== null) {
            $document
                ->setAttribute('hostname', parse_url($document->getAttribute('url', $document->getAttribute('hostname', '')), PHP_URL_HOST))
                ->removeAttribute('url')
            ;
        }
    }

    $document
        ->setAttribute('$id', $document->getAttribute('$uid', $document->getAttribute('$id')))
        ->removeAttribute('$uid')
    ;

    Console::log('Switched from $uid to $id: '.$document->getCollection().'/'.$document->getId());

    foreach($document as &$attr) {
        if($attr instanceof Document) {
            $attr = fixDocument($attr);
        }

        if(is_array($attr)) {
            foreach($attr as &$child) {
                if($child instanceof Document) {
                    $child = fixDocument($child);
                }
            }
        }
    }

    return $document;
}

$cli
    ->task('run')
    ->action(function () use ($console, $projectDB, $consoleDB, $callbacks) {
        Console::success('Starting Upgrade');

        Authorization::disable();

        $limit = 30;
        $sum = 30;
        $offset = 0;
        $projects = [$console];

        while ($sum >= 30) {
            foreach($projects as $project) {
                $projectDB->setNamespace('app_'.$project->getId());

                try {
                    $callbacks['0.5.0']($project);
                } catch (\Throwable $th) {
                    Console::error('Failed to update project ("'.$project->getId().'") version with error: '.$th->getMessage());
                    $projectDB->setNamespace('app_console');
                    $projectDB->deleteDocument($project->getId());
                }
            }

            $projects = $consoleDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'name',
                'orderType' => 'ASC',
                'orderCast' => 'string',
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_PROJECTS,
                ],
            ]);

            $sum = count($projects);
            $offset = $offset + $limit;

            Console::success('Fetched '.$sum.' projects...');
        }
    });

$cli->run();
