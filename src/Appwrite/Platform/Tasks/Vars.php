<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\Config\Config;
use Utopia\CLI\Console;
use Utopia\Platform\Action;

class Vars extends Action
{
    public static function getName(): string
    {
        return 'vars';
    }

    public function __construct()
    {
        $this
            ->desc('List all the server environment variables')
            ->callback($this->action);
    }

    public function action(): void
    {
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
    }
}
