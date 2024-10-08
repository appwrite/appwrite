<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Http\Validator\Boolean;
use Utopia\Http\Validator\Text;

class Upgrade extends Install
{
    public static function getName(): string
    {
        return 'upgrade';
    }

    public function __construct()
    {
        $this
            ->desc('Upgrade Appwrite')
            ->param('http-port', '', new Text(4), 'Server HTTP port', true)
            ->param('https-port', '', new Text(4), 'Server HTTPS port', true)
            ->param('organization', 'appwrite', new Text(0), 'Docker Registry organization', true)
            ->param('image', 'appwrite', new Text(0), 'Main appwrite docker image', true)
            ->param('interactive', 'Y', new Text(1), 'Run an interactive session', true)
            ->param('no-start', false, new Boolean(true), 'Run an interactive session', true)
            ->callback(fn ($httpPort, $httpsPort, $organization, $image, $interactive, $noStart) => $this->action($httpPort, $httpsPort, $organization, $image, $interactive, $noStart));
    }

    public function action(string $httpPort, string $httpsPort, string $organization, string $image, string $interactive, bool $noStart): void
    {
        // Check for previous installation
        $data = @file_get_contents($this->path . '/docker-compose.yml');
        if (empty($data)) {
            Console::error('Appwrite installation not found.');
            Console::log('The command was not run in the parent folder of your appwrite installation.');
            Console::log('Please navigate to the parent directory of the Appwrite installation and try again.');
            Console::log('  parent_directory <= you run the command in this directory');
            Console::log('  └── appwrite');
            Console::log('      └── docker-compose.yml');
            Console::exit(1);
        }
        parent::action($httpPort, $httpsPort, $organization, $image, $interactive, $noStart);
    }
}
