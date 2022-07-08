<?php
namespace Appwrite\Task;
use Utopia\App;
use Utopia\CLI\Task as CLITask;
use Utopia\Config\Config;
use Utopia\CLI\Console;


class Vars implements Task{
    private static CLITask $task;
    
    public static function getTask(): CLITask
    {
        $vars = new CLITask('vars');
        $vars
            ->desc('List all the server environment variables')
            ->action(function () {
                $config = Config::getParam('variables', []);
                $vars = [];
        
                foreach ($config as $category) {
                    foreach ($category['variables'] ?? [] as $var) {
                        $vars[] = $var;
                    }
                }
        
                foreach ($vars as $key => $value) {
                    Console::log('- ' . $value['name'] . '=' . App::getEnv($value['name'], ''));
                }
            });
        self::$task = $vars;
        return self::$task;
    }
}