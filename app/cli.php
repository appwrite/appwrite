<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Utopia\App;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use InfluxDB\Database as InfluxDatabase;
use Utopia\Database\Document;

function getInfluxDB(): InfluxDatabase
{
    global $register;

    $client = $register->get('influxdb'); /** @var InfluxDB\Client $client */
    $attempts = 0;
    $max = 10;
    $sleep = 1;

    do { // check if telegraf database is ready
        try {
            $attempts++;
            $database = $client->selectDB('telegraf');
            if (in_array('telegraf', $client->listDatabases())) {
                break; // leave the do-while if successful
            }
        } catch (\Throwable $th) {
            Console::warning("InfluxDB not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('InfluxDB database not ready yet');
            }
            sleep($sleep);
        }
    } while ($attempts < $max);
    return $database;
}

function getConsoleDB(): Database
{
    global $register;

    $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, getCache());

    $database->setNamespace('console');

    return $database;
}

function getProjectDB(Document $project): Database
{
    global $register;

    $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

    if ($project->isEmpty() || $project->getId() === 'console') {
        return getConsoleDB();
    }

    $dbAdapter = $pools
        ->get($project->getAttribute('database'))
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, getCache());
    $database->setNamespace('_' . $project->getInternalId());

    return $database;
}

function getCache(): Cache
{
    global $register;

    $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}

Authorization::disable();

$cli = new CLI();

include 'tasks/doctor.php';
include 'tasks/maintenance.php';
include 'tasks/install.php';
include 'tasks/migrate.php';
include 'tasks/sdks.php';
include 'tasks/specs.php';
include 'tasks/ssl.php';
include 'tasks/vars.php';
include 'tasks/usage.php';

$cli
    ->task('version')
    ->desc('Get the server version')
    ->action(function () {
        Console::log(App::getEnv('_APP_VERSION', 'UNKNOWN'));
    });

$cli
    ->error(function ($error) {
        if (App::getEnv('_APP_ENV', 'development')) {
            Console::error($error);
        } else {
            Console::error($error->getMessage());
        }
    });

$cli->run();
