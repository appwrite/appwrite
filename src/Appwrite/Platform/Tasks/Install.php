<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Auth\Auth;
use Appwrite\Docker\Compose;
use Appwrite\Docker\Env;
use Appwrite\Utopia\View;
use Utopia\Analytics\Adapter;
use Utopia\Analytics\Adapter\GoogleAnalytics;
use Utopia\Analytics\Event;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Validator\Text;
use Utopia\Platform\Action;

class Install extends Action
{
    protected string $path = '/usr/src/code/appwrite';

    public static function getName(): string
    {
        return 'install';
    }

    public function __construct()
    {
        $this
            ->desc('Install Appwrite')
            ->param('httpPort', '', new Text(4), 'Server HTTP port', true)
            ->param('httpsPort', '', new Text(4), 'Server HTTPS port', true)
            ->param('organization', 'appwrite', new Text(0), 'Docker Registry organization', true)
            ->param('image', 'appwrite', new Text(0), 'Main appwrite docker image', true)
            ->param('interactive', 'Y', new Text(1), 'Run an interactive session', true)
            ->callback(fn ($httpPort, $httpsPort, $organization, $image, $interactive) => $this->action($httpPort, $httpsPort, $organization, $image, $interactive));
    }

    public function action(string $httpPort, string $httpsPort, string $organization, string $image, string $interactive): void
    {
        $config = Config::getParam('variables');
        $defaultHTTPPort = '80';
        $defaultHTTPSPort = '443';
        /** @var array<string, array<string, string>> $vars array whre key is variable name and value is variable */
        $vars = [];

        /**
         * We are using a random value every execution for identification.
         * This allows us to collect information without invading the privacy of our users.
         */
        $analytics = new GoogleAnalytics('UA-26264668-9', uniqid('server.', true));

        foreach ($config as $category) {
            foreach ($category['variables'] ?? [] as $var) {
                $vars[$var['name']] = $var;
            }
        }

        Console::success('Starting Appwrite installation...');

        // Create directory with write permissions
        if (!\file_exists(\dirname($this->path))) {
            if (!@\mkdir(\dirname($this->path), 0755, true)) {
                Console::error('Can\'t create directory ' . \dirname($this->path));
                Console::exit(1);
            }
        }

        $data = @file_get_contents($this->path . '/docker-compose.yml');

        if ($data !== false) {
            if ($interactive == 'Y' && Console::isInteractive()) {
                $answer = Console::confirm('Previous installation found, do you want to overwrite it (a backup will be created before overwriting)? (Y/n)');

                if (\strtolower($answer) !== 'y') {
                    Console::info('No action taken.');
                    return;
                }
            }

            $time = \time();
            Console::info('Compose file found, creating backup: docker-compose.yml.' . $time . '.backup');
            file_put_contents($this->path . '/docker-compose.yml.' . $time . '.backup', $data);
            $compose = new Compose($data);
            $appwrite = $compose->getService('appwrite');
            $oldVersion = ($appwrite) ? $appwrite->getImageVersion() : null;
            try {
                $ports = $compose->getService('traefik')->getPorts();
            } catch (\Throwable $th) {
                $ports = [
                    $defaultHTTPPort => $defaultHTTPPort,
                    $defaultHTTPSPort => $defaultHTTPSPort
                ];
                Console::warning('Traefik not found. Falling back to default ports.');
            }

            if ($oldVersion) {
                foreach ($compose->getServices() as $service) { // Fetch all env vars from previous compose file
                    if (!$service) {
                        continue;
                    }

                    $env = $service->getEnvironment()->list();

                    foreach ($env as $key => $value) {
                        if (is_null($value)) {
                            continue;
                        }

                        $configVar = $vars[$key] ?? [];
                        if (!empty($configVar) && !($configVar['overwrite'] ?? false)) {
                            $vars[$key]['default'] = $value;
                        }
                    }
                }

                $data = @file_get_contents($this->path . '/.env');

                if ($data !== false) { // Fetch all env vars from previous .env file
                    Console::info('Env file found, creating backup: .env.' . $time . '.backup');
                    file_put_contents($this->path . '/.env.' . $time . '.backup', $data);
                    $env = new Env($data);

                    foreach ($env->list() as $key => $value) {
                        if (is_null($value)) {
                            continue;
                        }

                        $configVar = $vars[$key] ?? [];
                        if (!empty($configVar) && !($configVar['overwrite'] ?? false)) {
                            $vars[$key]['default'] = $value;
                        }
                    }
                }

                foreach ($ports as $key => $value) {
                    if ($value === $defaultHTTPPort) {
                        $defaultHTTPPort = $key;
                    }

                    if ($value === $defaultHTTPSPort) {
                        $defaultHTTPSPort = $key;
                    }
                }
            }
        }

        if (empty($httpPort)) {
            $httpPort = Console::confirm('Choose your server HTTP port: (default: ' . $defaultHTTPPort . ')');
            $httpPort = ($httpPort) ? $httpPort : $defaultHTTPPort;
        }

        if (empty($httpsPort)) {
            $httpsPort = Console::confirm('Choose your server HTTPS port: (default: ' . $defaultHTTPSPort . ')');
            $httpsPort = ($httpsPort) ? $httpsPort : $defaultHTTPSPort;
        }

        $input = [];

        foreach ($vars as $var) {
            if (!empty($var['filter']) && ($interactive !== 'Y' || !Console::isInteractive())) {
                if ($data && $var['default'] !== null) {
                    $input[$var['name']] = $var['default'];
                    continue;
                }

                if ($var['filter'] === 'token') {
                    $input[$var['name']] = Auth::tokenGenerator();
                    continue;
                }

                if ($var['filter'] === 'password') {
                    $input[$var['name']] = Auth::passwordGenerator();
                    continue;
                }
            }
            if (!$var['required'] || !Console::isInteractive() || $interactive !== 'Y') {
                $input[$var['name']] = $var['default'];
                continue;
            }

            $input[$var['name']] = Console::confirm($var['question'] . ' (default: \'' . $var['default'] . '\')');

            if (empty($input[$var['name']])) {
                $input[$var['name']] = $var['default'];
            }

            if ($var['filter'] === 'domainTarget') {
                if ($input[$var['name']] !== 'localhost') {
                    Console::warning("\nIf you haven't already done so, set the following record for {$input[$var['name']]} on your DNS provider:\n");
                    $mask = "%-15.15s %-10.10s %-30.30s\n";
                    printf($mask, "Type", "Name", "Value");
                    printf($mask, "A or AAAA", "@", "<YOUR PUBLIC IP>");
                    Console::warning("\nUse 'AAAA' if you're using an IPv6 address and 'A' if you're using an IPv4 address.\n");
                }
            }
        }

        $templateForCompose = new View(__DIR__ . '/../../../../app/views/install/compose.phtml');
        $templateForEnv = new View(__DIR__ . '/../../../../app/views/install/env.phtml');

        $templateForCompose
            ->setParam('httpPort', $httpPort)
            ->setParam('httpsPort', $httpsPort)
            ->setParam('version', APP_VERSION_STABLE)
            ->setParam('organization', $organization)
            ->setParam('image', $image);

        $templateForEnv->setParam('vars', $input);

        if (!file_put_contents($this->path . '/docker-compose.yml', $templateForCompose->render(false))) {
            $message = 'Failed to save Docker Compose file';
            $this->sendEvent($analytics, $message);
            Console::error($message);
            Console::exit(1);
        }

        if (!file_put_contents($this->path . '/.env', $templateForEnv->render(false))) {
            $message = 'Failed to save environment variables file';
            $this->sendEvent($analytics, $message);
            Console::error($message);
            Console::exit(1);
        }

        $env = '';
        $stdout = '';
        $stderr = '';

        foreach ($input as $key => $value) {
            if ($value) {
                $env .= $key . '=' . \escapeshellarg($value) . ' ';
            }
        }

        Console::log("Running \"docker compose up -d --remove-orphans --renew-anon-volumes\"");

        $exit = Console::execute("$env docker compose --project-directory $this->path up -d --remove-orphans --renew-anon-volumes", '', $stdout, $stderr);

        if ($exit !== 0) {
            $message = 'Failed to install Appwrite dockers';
            $this->sendEvent($analytics, $message);
            Console::error($message);
            Console::error($stderr);
            Console::exit($exit);
        } else {
            $message = 'Appwrite installed successfully';
            $this->sendEvent($analytics, $message);
            Console::success($message);
        }
    }

    private function sendEvent(Adapter $analytics, string $message): void
    {
        $event = new Event();
        $event->setName(APP_VERSION_STABLE);
        $event->setValue($message);
        $event->setUrl('http://localhost/');
        $event->setProps([
            'category' => 'install/server',
            'action' => 'install',
        ]);
        $event->setType('install/server');

        $analytics->createEvent($event);
    }
}
