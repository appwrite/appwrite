<?php

use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Utopia\CLI\Console;
use Utopia\Config\Config;

require_once __DIR__.'/../init.php';

\cli_set_process_title('Database V1 Worker');

Console::success(APP_NAME.' database worker v1 has started');

class DatabaseV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $register;

        $projectId = $this->args['projectId'];
        $operation = $this->args['operation'];
        
        $collection = $this->args['collection'];
        $id = $this->args['id'];
        $type = $this->args['type'];
        $array = $this->args['array'];
        $attributes = $this->args['attributes'];

        $database = new Database();
        $database->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $database->setNamespace('app_'.$projectId);
        $database->setMocks(Config::getParam('collections', []));

        switch ($operation) {
            case 'createAttribute':
                $database->createAttribute($collection, $id, $type, $array);
                break;

            case 'deleteAttribute':
                $database->deleteAttribute($collection, $id, $array);
                break;

            case 'createIndex':
                $database->createIndex($collection, $id, $attributes);
                break;

            case 'deleteIndex':
                $database->deleteIndex($collection, $id);
                break;
            
            default:
                # code...
                break;
        }
    }

    public function tearDown()
    {
    }
}
