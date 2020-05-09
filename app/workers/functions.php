<?php

use Utopia\Config\Config;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Utopia\CLI\Console;

require_once __DIR__.'/../init.php';

cli_set_process_title('Functions V1 Worker');

Console::success(APP_NAME.' functions worker v1 has started');

$envs = [
    'node:14',
    'php:7.4-cli',
    'sdaskdjaksdjaksjda',
];

foreach($envs as $env) {
    $stdout = '';
    $stderr = '';
    Console::execute('docker pull '.$env, null, $stdout, $stderr);

    var_dump($stdout);
    var_dump($stderr);
}

class FunctionsV1
{
    public $args = [];

    public $images = [
        
    ];

    public function setUp()
    {
    }

    public function perform()
    {
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
