<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Docker\Compose;
use Appwrite\Docker\Env;
use Utopia\Console;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

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
            ->param('database', '', new Text(length: 0, min: 0), 'Ignored: an upgrade always keeps the database the installation already uses', true)
            ->param('topology', 'combined', new WhiteList(['combined', 'separate']), 'Worker and scheduler topology (combined|separate)', true)
            ->param('migrate', false, new Boolean(true), 'Run database migration after upgrade', true)
            ->callback($this->action(...));
    }

    public function action(
        string $httpPort,
        string $httpsPort,
        string $organization,
        string $image,
        string $interactive,
        bool $noStart,
        string $database,
        string $topology = 'combined',
        bool $migrate = false,
    ): void {
        $this->isUpgrade = true;
        $this->migrate = $migrate;
        $isLocalInstall = $this->isLocalInstall();
        $this->applyLocalPaths($isLocalInstall, true);

        // Check for previous installation
        $data = $this->readExistingCompose();
        if (empty($data)) {
            Console::error('Appwrite installation not found.');
            Console::log('The command was not run in the parent folder of your appwrite installation.');
            Console::log('Please navigate to the parent directory of the Appwrite installation and try again.');
            Console::log('  parent_directory <= you run the command in this directory');
            Console::log('  └── appwrite');
            Console::log('      └── ' . $this->getComposeFileName());
            return;
        }

        // An upgrade always keeps the engine the installation already uses, so the
        // requested value is only kept to tell the user it is being ignored.
        $requestedDatabase = $database;
        $database = null;
        $compose = new Compose($data);
        foreach ($compose->getServices() as $service) {
            $env = $service->getEnvironment()->list();
            if (isset($env['_APP_DB_ADAPTER'])) {
                $database = $env['_APP_DB_ADAPTER'];
                break;
            }
        }

        if ($database === null) {
            $envData = @file_get_contents($this->path . '/' . $this->getEnvFileName());
            if ($envData !== false) {
                $envFile = new Env($envData);
                $database = $envFile->list()['_APP_DB_ADAPTER'] ?? null;
            }
        }

        if ($database === null) {
            // Pre-1.9.0 installations only supported MariaDB
            $database = 'mariadb';
            Console::info('No _APP_DB_ADAPTER found in existing configuration, defaulting to mariadb.');
        }

        if ($requestedDatabase !== '' && $requestedDatabase !== $database) {
            Console::warning(
                "Ignoring --database={$requestedDatabase}: this installation uses {$database} and an upgrade preserves it."
                . " Appwrite cannot move an existing installation between database engines;"
                . " switching requires a new installation and transferring your data into it."
            );
        }

        $this->lockedDatabase = $database;

        parent::action($httpPort, $httpsPort, $organization, $image, $interactive, $noStart, $database, $topology);
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
