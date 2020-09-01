<?php

global $cli, $register, $projectDB, $console;

use Utopia\Config\Config;
use Utopia\CLI\Console;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;

$db = $register->get('db');

$callbacks = [
    '0.4.0' => function() {
        Console::log('I got nothing to do.');
    },
    '0.5.0' => function(Document $project, Database $projectDB) use ($db) {

        Console::log('Migrating project: '.$project->getAttribute('name').' ('.$project->getId().')');

        // Update all documents $uid -> $id

        $limit = 30;
        $sum = 30;
        $offset = 0;

        while ($sum >= 30) {
            $all = $projectDB->find([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => '$uid',
                'orderType' => 'DESC',
                'orderCast' => 'string',
            ]);

            $sum = \count($all);
            
            Console::log('Migrating: '.$offset.' / '.$projectDB->getSum());
            
            foreach($all as $document) {
                $document = fixDocument($document);

                if(empty($document->getId())) {
                    throw new Exception('Missing ID');
                }

                try {
                    $new = $projectDB->overwriteDocument($document->getArrayCopy());
                } catch (\Throwable $th) {
                    var_dump($document);
                    Console::error('Failed to update document: '.$th->getMessage());
                    continue;
                }

                if($new->getId() !== $document->getId()) {
                    throw new Exception('Duplication Error');
                }
            }

            $offset = $offset + $limit;
        }

        $schema = (isset($_SERVER['_APP_DB_SCHEMA'])) ? $_SERVER['_APP_DB_SCHEMA'] : '';

        try {
            $statement = $db->prepare("

                CREATE TABLE IF NOT EXISTS `template.database.unique` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `key` varchar(128) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `index1` (`key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `{$schema}`.`app_{$project->getId()}.database.unique` LIKE `template.database.unique`;
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
    $providers = Config::getParam('providers');

    if($document->getAttribute('$collection') === Database::COLLECTION_PROJECTS){
        foreach($providers as $key => $provider) {
            if(!empty($document->getAttribute('usersOauth'.\ucfirst($key).'Appid'))) {
                $document
                    ->setAttribute('usersOauth2'.\ucfirst($key).'Appid', $document->getAttribute('usersOauth'.\ucfirst($key).'Appid', ''))
                    ->removeAttribute('usersOauth'.\ucfirst($key).'Appid')
                ;
            }

            if(!empty($document->getAttribute('usersOauth'.\ucfirst($key).'Secret'))) {
                $document
                    ->setAttribute('usersOauth2'.\ucfirst($key).'Secret', $document->getAttribute('usersOauth'.\ucfirst($key).'Secret', ''))
                    ->removeAttribute('usersOauth'.\ucfirst($key).'Secret')
                ;
            }
        }
    }

    if($document->getAttribute('$collection') === Database::COLLECTION_WEBHOOKS){
        $document->setAttribute('security', ($document->getAttribute('security')) ? true : false);
    }

    if($document->getAttribute('$collection') === Database::COLLECTION_TASKS){
        $document->setAttribute('security', ($document->getAttribute('security')) ? true : false);
    }

    if($document->getAttribute('$collection') === Database::COLLECTION_USERS) {
        foreach($providers as $key => $provider) {
            if(!empty($document->getAttribute('oauth'.\ucfirst($key)))) {
                $document
                    ->setAttribute('oauth2'.\ucfirst($key), $document->getAttribute('oauth'.\ucfirst($key), ''))
                    ->removeAttribute('oauth'.\ucfirst($key))
                ;
            }

            if(!empty($document->getAttribute('oauth'.\ucfirst($key).'AccessToken'))) {
                $document
                    ->setAttribute('oauth2'.\ucfirst($key).'AccessToken', $document->getAttribute('oauth'.\ucfirst($key).'AccessToken', ''))
                    ->removeAttribute('oauth'.\ucfirst($key).'AccessToken')
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

    if($document->getAttribute('$collection') === Database::COLLECTION_PLATFORMS) {
        if($document->getAttribute('url', null) !== null) {
            $document
                ->setAttribute('hostname', \parse_url($document->getAttribute('url', $document->getAttribute('hostname', '')), PHP_URL_HOST))
                ->removeAttribute('url')
            ;
        }
    }

    $document
        ->setAttribute('$id', $document->getAttribute('$uid', $document->getAttribute('$id')))
        ->removeAttribute('$uid')
    ;

    foreach($document as &$attr) { // Handle child documents
        if($attr instanceof Document) {
            $attr = fixDocument($attr);
        }

        if(\is_array($attr)) {
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
    ->task('migrate')
    ->action(function () use ($register, $callbacks) {
        Console::success('Starting Data Migration');

        $consoleDB = new Database();
        $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $consoleDB->setNamespace('app_console'); // Main DB
        $consoleDB->setMocks(Config::getParam('collections', []));
        
        $projectDB = new Database();
        $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $projectDB->setMocks(Config::getParam('collections', []));

        $console = $consoleDB->getDocument(Database::COLLECTION_PROJECTS, 'console');

        Authorization::disable();

        $limit = 30;
        $sum = 30;
        $offset = 0;
        $projects = [$console];
        $count = 0;

        while ($sum >= 30) {
            foreach($projects as $project) {
                
                $projectDB->setNamespace('app_'.$project->getId());

                try {
                    $callbacks['0.5.0']($project, $projectDB);
                } catch (\Throwable $th) {
                    throw $th;
                    Console::error('Failed to update project ("'.$project->getId().'") version with error: '.$th->getMessage());
                }
            }

            $projects = $consoleDB->find(Database::COLLECTION_PROJECTS, [
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'name',
                'orderType' => 'ASC',
                'orderCast' => 'string',
            ]);

            $sum = \count($projects);
            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Fetched '.$count.'/'.$consoleDB->getSum().' projects...');
        }

        Console::success('Data Migration Completed');
    });