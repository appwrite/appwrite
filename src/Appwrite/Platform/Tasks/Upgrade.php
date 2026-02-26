<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Docker\Compose;
use Appwrite\Docker\Env;
use Utopia\CLI\Console;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Upgrade extends Install
{
    private ?string $lockedDatabase = null;

    public static function getName(): string
    {
        return 'upgrade';
    }

    public function __construct()
    {
        parent::__construct();

        $this
            ->desc('Upgrade Appwrite')
            ->param('http-port', '', new Text(4), 'Server HTTP port', true)
            ->param('https-port', '', new Text(4), 'Server HTTPS port', true)
            ->param('organization', 'appwrite', new Text(0), 'Docker Registry organization', true)
            ->param('image', 'appwrite', new Text(0), 'Main appwrite docker image', true)
            ->param('interactive', 'Y', new Text(1), 'Run an interactive session', true)
            ->param('no-start', false, new Boolean(true), 'Run an interactive session', true)
            ->param('database', 'mongodb', new Text(length: 0), 'Database to use (mongodb|mariadb|postgresql)', true)
            ->callback($this->action(...));
    }

    public function action(
        string $httpPort,
        string $httpsPort,
        string $organization,
        string $image,
        string $interactive,
        bool $noStart,
        string $database
    ): void {
        $isLocalInstall = $this->isLocalInstall();
        $this->applyLocalPaths($isLocalInstall, true);

        // Check for previous installation
        if (empty($this->readExistingCompose())) {
            Console::error('Appwrite installation not found.');
            Console::log('The command was not run in the parent folder of your appwrite installation.');
            Console::log('Please navigate to the parent directory of the Appwrite installation and try again.');
            Console::log('  parent_directory <= you run the command in this directory');
            Console::log('  └── appwrite');
            Console::log('      └── ' . $this->getComposeFileName());
            return;
        }

        $database = null;
        $compose = new Compose($data);
        foreach ($compose->getServices() as $service) {
            if (!$service) {
                continue;
            }
            $env = $service->getEnvironment()->list();
            if (isset($env['_APP_DB_ADAPTER'])) {
                $database = $env['_APP_DB_ADAPTER'];
                break;
            }
        }

        if ($database === null) {
            $envData = @file_get_contents($this->path . '/.env');
            if ($envData !== false) {
                $envFile = new Env($envData);
                $database = $envFile->list()['_APP_DB_ADAPTER'] ?? null;
            }
        }

        if ($database === null) {
            throw new \Exception('Database type not found, can not updgrade. Ensure `_APP_DB_ADAPTER` is set in your environment.');
        }

        parent::action($httpPort, $httpsPort, $organization, $image, $interactive, $noStart, $database);
    }

    protected function startWebServer(
        string $defaultHttpPort,
        string $defaultHttpsPort,
        string $organization,
        string $image,
        bool $noStart,
        array $vars,
        bool $isUpgrade = false,
        ?string $lockedDatabase = null
    ): void {
        parent::startWebServer(
            $defaultHttpPort,
            $defaultHttpsPort,
            $organization,
            $image,
            $noStart,
            $vars,
            true,
            $this->lockedDatabase
        );
    }
}
