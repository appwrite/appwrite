<?php

namespace Appwrite\Task;

use Utopia\CLI\Task as CLITask;

interface Task {
    public static function getTask(): CLITask;
}