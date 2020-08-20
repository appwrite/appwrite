<?php

use Utopia\CLI\Console;

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
    }

    public function tearDown()
    {
    }
}
