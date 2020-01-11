#!/bin/env php
<?php

require_once __DIR__.'/../init.php';

global $register;

use Utopia\CLI\CLI;
use Utopia\CLI\Console;

$cli = new CLI();
$db = $register->get('db');

$callbacks = [
    '1.0.1' => function() {
        Console::log('I got nothing to do.');
    },
    '1.0.2' => function($tables) use ($db) {

        foreach($tables as $node) {
            $table = $node['table'];
            $project = $node['project'];
            $namespace = $node['namespace'];
            $name = $node['name'];
            
            if (($namespace !== 'audit') || ($name !== 'audit')) {
                continue;
            }

            Console::info('Altering table: '.$table);

            try {
                $statement = $db->prepare("
                    ALTER TABLE `appwrite`.`{$project}.audit.audit` DROP COLUMN IF EXISTS `userType`;
                    ALTER TABLE `appwrite`.`{$project}.audit.audit` DROP INDEX IF EXISTS `index_1`;
                    ALTER TABLE `appwrite`.`{$project}.audit.audit` ADD INDEX IF NOT EXISTS `index_1` (`userId` ASC);
                ");

                $statement->execute();
            }
            catch (\Exception $e) {
                Console::error($e->getMessage().'/');
            }
            
        }
    },
];

$cli
    ->task('run')
    ->action(function () use ($db, $callbacks) {
        Console::success('Starting Upgrade');
        
        $statment = $db->query('SELECT table_name FROM information_schema.tables WHERE table_schema = "appwrite";');
        
        $tables = array_map(function($node) {
            $name = explode('.', $node['table_name']);

            return [
                'table' => implode('.', $name),
                'project' => array_shift($name),
                'namespace' => array_shift($name),
                'name' => array_shift($name),
            ];
        }, $statment->fetchAll());
        
        $callbacks['1.0.2']($tables);
    });

$cli->run();
