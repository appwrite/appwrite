<?php

use Appwrite\Resque\Worker;
use Utopia\CLI\Console;

require_once __DIR__ . '/../init.php';

Console::title('Syncs in V1 Worker');
Console::success(APP_NAME . ' syncs in worker v1 has started');

class SyncsInV1 extends Worker
{
    protected array $errors = [];

    public function getName(): string
    {
        return "syncs-in";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
    }

    public function shutdown(): void
    {
    }
}
