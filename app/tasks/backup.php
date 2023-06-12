<?php

global $cli, $register;

use Utopia\CLI\Console;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\None;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;

# example:
# docker compose exec appwrite backup --projectId=648704ab178219be05c0

$cli
    ->task('backup')
    ->param('projectId', '', new UID(), 'Project id for backup', false)
    ->action(function ($projectId) use ($register) {
        Authorization::disable();
        $app = new App('UTC');
        Console::success('Starting Data Backup for project ' . $projectId);

        /**
         * @var $db \PDO
         */
        $db = $register->get('db', true);

        $cache = new Cache(new None());

        // todo: we want the adapter dynamic....
        $projectDB = new Database(new MariaDB($db), $cache);
        $projectDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $projectDB->setNamespace('_1'); // todo get internal id from project id

        $consoleDB = new Database(new MariaDB($db), $cache);
        $consoleDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $consoleDB->setNamespace('_console');

        // $console = $app->getResource('console');
        //$project = $consoleDB->find('projects', [Query::equal('$uid', [$projectId])]);
        $project = $consoleDB->getDocument('projects', $projectId);

        $collections = Config::getParam('collections', []);

        $stack = [];
        foreach ($collections as $collection) {
        //  var_dump($collection['$id']);

            if (in_array($collection['$id'], ['files', 'collections', 'stats'])) {
                continue;
            }

            $name = "`" . $projectDB->getDefaultDatabase() . "`.`_" . $project->getInternalId() . "_" . $collection['$id'] . '`';

            $stack[] = $name;

            switch ($collection['$id']) {
                case 'databases':
                    $databases = $projectDB->find('databases', []);
                    foreach ($databases as $database) {
                        $name = "database_{$database->getInternalId()}";
                        $stack[] = $name;
                        $databaseCollections = $projectDB->find($name, []);
                        foreach ($databaseCollections as $databaseCollection) {
                            $stack[] = $name . '_collection_' . $databaseCollection->getInternalId();
                        }
                    }
                    var_dump($stack);
                    break;
                case 'buckets':
                    echo "buckets";
                    break;
            }
        }

        var_dump($stack);
        die;

        $schema = 'appwrite';
       // var_dump($stack);

        $destination = './file.sql';
        $return = null;
        $output = null;
        $command = "mysqldump -u user -h 127.0.0.1 " . $schema . " > " . $destination;
       // $command = "which mysqldump ";
        exec($command, $output, $return);

        var_dump($output);
        var_dump($return);

        die;



        if ($project->getId() === 'console' && $project->getInternalId() !== 'console') {
            // todo: make sure not to backup console?
        }

//        $migration
//            ->setProject($project, $projectDB, $consoleDB)
//            ->setPDO($register->get('db'))
//            ->execute();

        Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Complete');
    });
