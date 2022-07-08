<?php
namespace Appwrite\Task;

use Utopia\CLI\CLI;
use Appwrite\Task\Usage;
use Appwrite\Task\Version;

class Tasks {
    protected static CLI $cli;


    public static function init(): void
    {
        self::$cli = new CLI();
        self::$cli->addTask(Vars::getTask());
        self::$cli->addTask(Usage::getTask());
        self::$cli->addTask(Version::getTask());
    }

    public static function getCli(): CLI
    {
        return self::$cli;
    }
}
