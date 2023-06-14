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

        $tables = [];
        foreach ($collections as $collection) {
            if (in_array($collection['$id'], ['files', 'collections'])) {
                continue;
            }

//            $name = "`" . $projectDB->getDefaultDatabase() . "`.`_" . $project->getInternalId() . "_" . $collection['$id'] . '`';
//            $name = "`_" . $project->getInternalId() . "_" . $collection['$id'] . '`';
//            $name = $projectDB->getNamespace() . "_" . $collection['$id'];

            $tables[] = $projectDB->getNamespace() . "_" . $collection['$id'];

            switch ($collection['$id']) {
                case 'databases':
                    $databases = $projectDB->find('databases', []);
                    foreach ($databases as $database) {
                        $tables[] = $projectDB->getNamespace() . '_database_' . $database->getInternalId();
                        $databaseCollections = $projectDB->find('database_' . $database->getInternalId(), []);
                        foreach ($databaseCollections as $databaseCollection) {
                            $tables[] = $projectDB->getNamespace() . '_database_' . $database->getInternalId() . '_collection_' . $databaseCollection->getInternalId();
                        }
                    }

                    break;
                case 'buckets':
                    $buckets = $projectDB->find('buckets', []);
                    foreach ($buckets as $bucket) {
                        $tables[] = $projectDB->getNamespace() . '_bucket_' . $bucket->getInternalId();
                    }
                    break;
            }
        }

        var_dump($tables);

        $schema = 'appwrite';

        $destination = './file.sql';
        $return = null;
        $output = null;
        $singleTransaction = '--single-transaction';
        $tables = implode(' ', $tables);
        //$command = "mysqldump -u user -h bla " . $schema . " " . implode(' ', $tables) . "> " . $destination;
        $command = "docker exec appwrite-mariadb /usr/bin/mysqldump -u root --password=rootsecretpassword " . $schema . " " . $tables . " " . $singleTransaction . " > backup.sql";

        Console::error($command);
        //exec($command, $output, $return);

        var_dump($output);
        var_dump($return);

        $restore = "docker exec -i appwrite-mariadb mysql -u root --password=rootsecretpassword shmuel < backup.sql";
        Console::error($restore);

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
