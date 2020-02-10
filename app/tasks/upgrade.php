#!/bin/env php
<?php

require_once __DIR__.'/../init.php';

global $register, $projectDB, $console;

use Database\Database;
use Database\Validator\Authorization;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;

$cli = new CLI();
$db = $register->get('db');

$callbacks = [
    '0.4.0' => function() {
        Console::log('I got nothing to do.');
    },
    '0.5.0' => function($project) use ($db, $projectDB) {

        Console::info('Altering table for project: '.$project->getUid());

        try {
            $statement = $db->prepare("
                ALTER TABLE `appwrite`.`app_{$project->getUid()}.audit.audit` DROP COLUMN IF EXISTS `userType`;
                ALTER TABLE `appwrite`.`app_{$project->getUid()}.audit.audit` DROP INDEX IF EXISTS `index_1`;
                ALTER TABLE `appwrite`.`app_{$project->getUid()}.audit.audit` ADD INDEX IF NOT EXISTS `index_1` (`userId` ASC);
            ");

            $statement->execute();
        }
        catch (\Exception $e) {
            Console::error($e->getMessage().'/');
        }

        $statement->closeCursor();

        $limit = 30;
        $sum = 30;
        $offset = 0;

        while ($sum >= 30) {
            $users = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'name',
                'orderType' => 'ASC',
                'orderCast' => 'string',
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                ],
            ]);

            $sum = count($users);
            
            Console::success('Fetched '.$sum.' users...');

            foreach($users as $user) {
                $user
                    ->setAttribute('emailVerification', $user->getAttribute('confirm', false))
                    ->removeAttribute('confirm')
                ;

                if(!$projectDB->updateDocument($user->getArrayCopy())) {
                    Console::error('Failed to update user');
                }
                else {
                    Console::success('Updated user succefully');
                }
            }

            $offset = $offset + $limit;
        }
    },
];

$cli
    ->task('run')
    ->action(function () use ($console, $db, $projectDB, $consoleDB, $callbacks) {
        Console::success('Starting Upgrade');

        Authorization::disable();

        $limit = 30;
        $sum = 30;
        $offset = 0;
        $projects = [$console];

        while ($sum >= 30) {
            foreach($projects as $project) {
                $projectDB->setNamespace('app_'.$project->getUid());

                $callbacks['0.5.0']($project);
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
