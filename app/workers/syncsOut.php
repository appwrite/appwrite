<?php

use Appwrite\Resque\Worker;
use Utopia\CLI\Console;

require_once __DIR__ . '/../init.php';

Console::title('Syncs out V1 Worker');
Console::success(APP_NAME . ' syncs out worker v1 has started');

class SyncsOutV1 extends Worker
{
    protected array $errors = [];

    public function getName(): string
    {
        return "syncs-out";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        var_dump('run');
        var_dump($this->args['key']);
    }


    public function shutdown(): void
    {
    }
}
