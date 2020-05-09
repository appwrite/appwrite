<?php

use Utopia\CLI\Console;
use Utopia\Config\Config;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;

require_once __DIR__.'/../init.php';

cli_set_process_title('Functions V1 Worker');

Console::success(APP_NAME.' functions worker v1 has started');

$environments = Config::getParam('environments');

foreach($environments as $environment) {
    $stdout = '';
    $stderr = '';
    Console::execute('docker pull '.$environment['image'], null, $stdout, $stderr);

    if(!empty($stdout)) {
        Console::success($stdout);
    }

    if(!empty($stderr)) {
        Console::error($stderr);
    }
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
