<?php
namespace Appwrite\Task;

use Utopia\CLI\CLI;
use Appwrite\Task\Usage;
use Appwrite\Task\Version;

class Tasks {
    protected CLI $cli;

    public function init(): Tasks
    {
        $this->cli = new CLI();
        $this->cli->addTask(Vars::getTask());
        $this->cli->addTask(Usage::getTask());
        $this->cli->addTask(Version::getTask());
        return $this;
    }

    public function run(): CLI
    {
        return $this->cli->run();
    }
}
