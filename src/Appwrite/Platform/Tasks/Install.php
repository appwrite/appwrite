<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Docker\Compose;
use Appwrite\Docker\Env;
use Appwrite\Platform\Installer\Server as InstallerServer;
use Appwrite\Utopia\View;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Token;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Platform\Action;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Install extends Action
{
    private const int INSTALL_STEP_DELAY_SECONDS = 2;

    protected string $path = '/usr/src/code/appwrite';

    public static function getName(): string
    {
        return 'install';
    }

    public function __construct()
    {
        $this
            ->desc('Install Appwrite')
            ->param('http-port', '', new Text(4), 'Server HTTP port', true)
            ->param('https-port', '', new Text(4), 'Server HTTPS port', true)
            ->param('organization', 'appwrite', new Text(0), 'Docker Registry organization', true)
            ->param('image', 'appwrite', new Text(0), 'Main appwrite docker image', true)
            ->param('interactive', 'Y', new Text(1), 'Run an interactive session', true)
            ->param('no-start', false, new Boolean(true), 'Run an interactive session', true)
            ->callback($this->action(...));

    }

    public function action(string $httpPort, string $httpsPort, string $organization, string $image, string $interactive, bool $noStart, bool $isUpgrade = false): void
    {
        $config = Config::getParam('variables');
        $defaultHTTPPort = '80';
        $defaultHTTPSPort = '443';
        /** @var array<string, array<string, string>> $vars array where key is variable name and value is variable */
        $vars = [];

        foreach ($config as $category) {
            foreach ($category['variables'] ?? [] as $var) {
                $vars[$var['name']] = $var;
            }
        }

        Console::success('Starting Appwrite installation...');

        $isLocalInstall = !empty(getenv('APPWRITE_INSTALLER_LOCAL'));

        // Create directory with write permissions
        if (!\file_exists(\dirname($this->path))) {
            if (!@\mkdir(\dirname($this->path), 0755, true)) {
                Console::error('Can\'t create directory ' . \dirname($this->path));
                Console::exit(1);
            }
        }

        // Check for existing installation
        $data = @file_get_contents($this->path . '/docker-compose.yml');
        $existingInstallation = $data !== false;

        if ($existingInstallation) {
            $time = \time();
            if (!$isLocalInstall) {
                Console::info('Compose file found, creating backup: docker-compose.yml.' . $time . '.backup');
                file_put_contents($this->path . '/docker-compose.yml.' . $time . '.backup', $data);
            }
            $compose = new Compose($data);
            $appwrite = $compose->getService('appwrite');
            $oldVersion = $appwrite?->getImageVersion();
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
                foreach ($compose->getServices() as $service) {
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

                $envData = @file_get_contents($this->path . '/.env');

                if ($envData !== false) {
                    if (!$isLocalInstall) {
                        Console::info('Env file found, creating backup: .env.' . $time . '.backup');
                        file_put_contents($this->path . '/.env.' . $time . '.backup', $envData);
                    }
                    $env = new Env($envData);

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

        // If interactive and web mode enabled, start web server
        if ($interactive === 'Y' && Console::isInteractive()) {
            Console::success('Starting web installer...');
            Console::info('Open your browser at: http://localhost:' . InstallerServer::INSTALLER_WEB_PORT);
            Console::info('Press Ctrl+C to cancel installation');

            $this->startWebServer($defaultHTTPPort, $defaultHTTPSPort, $organization, $image, $noStart, $vars);
            return;
        }

        // Fall back to CLI mode
        $enableAssistant = false;
        $assistantExistsInOldCompose = false;
        if ($existingInstallation && isset($compose)) {
            try {
                $assistantService = $compose->getService('appwrite-assistant');
                $assistantExistsInOldCompose = $assistantService !== null;
            } catch (\Throwable) {
                /* ignore */
            }
        }

        if ($interactive === 'Y' && Console::isInteractive()) {
            $prompt = 'Add Appwrite Assistant? (Y/n)' . ($assistantExistsInOldCompose ? ' [Currently enabled]' : '');
            $answer = Console::confirm($prompt);

            if (empty($answer)) {
                $enableAssistant = $assistantExistsInOldCompose;
            } else {
                $enableAssistant = \strtolower($answer) === 'y';
            }
        } elseif ($assistantExistsInOldCompose) {
            $enableAssistant = true;
        }

        if (empty($httpPort)) {
            $httpPort = Console::confirm('Choose your server HTTP port: (default: ' . $defaultHTTPPort . ')');
            $httpPort = ($httpPort) ? $httpPort : $defaultHTTPPort;
        }

        if (empty($httpsPort)) {
            $httpsPort = Console::confirm('Choose your server HTTPS port: (default: ' . $defaultHTTPSPort . ')');
            $httpsPort = ($httpsPort) ? $httpsPort : $defaultHTTPSPort;
        }

        $userInput = [];
        foreach ($vars as $var) {
            if ($var['name'] === '_APP_ASSISTANT_OPENAI_API_KEY') {
                if (!$enableAssistant) {
                    $userInput[$var['name']] = '';
                    continue;
                }

                if (!empty($var['default'])) {
                    $userInput[$var['name']] = $var['default'];
                    continue;
                }

                if (Console::isInteractive() && $interactive === 'Y') {
                    $userInput[$var['name']] = Console::confirm('Enter your OpenAI API key for Appwrite Assistant:');
                    if (empty($userInput[$var['name']])) {
                        Console::warning('No API key provided. Assistant will be disabled.');
                        $enableAssistant = false;
                        $userInput[$var['name']] = '';
                    }
                } else {
                    $userInput[$var['name']] = '';
                }

                continue;
            }

            if (!$var['required'] || !Console::isInteractive() || $interactive !== 'Y') {
                continue;
            }

            $value = Console::confirm($var['question'] . ' (default: \'' . $var['default'] . '\')');

            if (!empty($value)) {
                $userInput[$var['name']] = $value;
            }

            if ($var['filter'] === 'domainTarget' && !empty($value) && $value !== 'localhost') {
                Console::warning("\nIf you haven't already done so, set the following record for {$value} on your DNS provider:\n");
                $mask = "%-15.15s %-10.10s %-30.30s\n";
                printf($mask, "Type", "Name", "Value");
                printf($mask, "A or AAAA", "@", "<YOUR PUBLIC IP>");
                Console::warning("\nUse 'AAAA' if you're using an IPv6 address and 'A' if you're using an IPv4 address.\n");
            }
        }

        $input = $this->prepareEnvironmentVariables($userInput, $vars);
        $this->performInstallation($httpPort, $httpsPort, $organization, $image, $input, $noStart, null, null, $isUpgrade);
    }


    protected function startWebServer(string $defaultHTTPPort, string $defaultHTTPSPort, string $organization, string $image, bool $noStart, array $vars, bool $isUpgrade = false, ?string $lockedDatabase = null): void
    {
        $port = getenv('APPWRITE_INSTALLER_LOCAL')
            ? InstallerServer::INSTALLER_WEB_PORT_INTERNAL
            : InstallerServer::INSTALLER_WEB_PORT;
        $host = '0.0.0.0';

        // Create a router script for handling requests
        $routerScript = \sys_get_temp_dir() . '/appwrite-installer-router.php';
        $this->createRouterScript($routerScript, $defaultHTTPPort, $defaultHTTPSPort, $organization, $image, $noStart, $vars, $isUpgrade, $lockedDatabase);

        // Start PHP built-in server in background
        $command = \sprintf(
            'php -S %s:%d %s >/dev/null 2>&1 & echo $!',
            $host,
            $port,
            \escapeshellarg($routerScript)
        );

        // Start the server
        $output = [];
        $exitCode = 0;
        \exec($command, $output, $exitCode);
        $pid = isset($output[0]) ? (int) $output[0] : 0;

        \register_shutdown_function(function () use ($routerScript, $pid) {
            if (\file_exists($routerScript)) {
                \unlink($routerScript);
            }
            if ($pid > 0 && \function_exists('posix_kill')) {
                @\posix_kill($pid, SIGTERM);
            }
        });
        \sleep(3);

        // Check if the server actually started
        $handle = @fsockopen('localhost', $port, $errno, $errstr, 1);
        if ($handle === false) {
            Console::exit(1);
        }
        \fclose($handle);

        // Wait for the server process to finish
        while (true) {
            $handle = @fsockopen('localhost', $port, $errno, $errstr, 1);
            if ($handle === false) {
                break;
            }
            \fclose($handle);
            \sleep(1);
        }

        // Cleanup
        if (\file_exists($routerScript)) {
            \unlink($routerScript);
        }
    }

    private function createRouterScript(string $path, string $defaultHTTPPort, string $defaultHTTPSPort, string $organization, string $image, bool $noStart, array $vars, bool $isUpgrade = false, ?string $lockedDatabase = null): void
    {
        $installPhpPath = __FILE__;
        $appViewsPath = __DIR__ . '/../../../../app/views/install';
        $publicPath = __DIR__ . '/../../../../public';
        $vendorPath = __DIR__ . '/../../../../vendor/autoload.php';
        $appwritePath = __DIR__ . '/../../../../app/init.php';

        InstallerServer::writeRouterScript(
            $path,
            $installPhpPath,
            $appViewsPath,
            $publicPath,
            $vendorPath,
            $appwritePath,
            $defaultHTTPPort,
            $defaultHTTPSPort,
            $organization,
            $image,
            $noStart,
            $vars,
            $isUpgrade,
            $lockedDatabase
        );
    }

    public function prepareEnvironmentVariables(array $userInput, array $vars): array
    {
        $input = [];
        $password = new Password();
        $token = new Token();

        // Start with all defaults
        foreach ($vars as $var) {
            if (!empty($var['filter']) && $var['filter'] === 'token') {
                $input[$var['name']] = $token->generate();
            } elseif (!empty($var['filter']) && $var['filter'] === 'password') {
                $input[$var['name']] = $password->generate();
            } else {
                $input[$var['name']] = $var['default'];
            }
        }

        // Override with user inputs
        foreach ($userInput as $key => $value) {
            if ($value !== null && ($value !== '' || $key === '_APP_ASSISTANT_OPENAI_API_KEY')) {
                $input[$key] = $value;
            }
        }

        // Set database-specific connection details
        $database = $input['_APP_DB_ADAPTER'] ?? 'mongodb';
        if ($database === 'mongodb') {
            $input['_APP_DB_HOST'] = 'mongodb';
            $input['_APP_DB_PORT'] = 27017;
        } elseif ($database === 'mariadb') {
            $input['_APP_DB_HOST'] = 'mariadb';
            $input['_APP_DB_PORT'] = 3306;
        }

        return $input;
    }

    private function reportProgress(?callable $progress, string $step, string $status, string $message, array $details = []): void
    {
        if (!$progress) {
            return;
        }

        try {
            $progress($step, $status, $message, $details);
        } catch (\Throwable $e) {
        }
    }

    private function delayProgress(?callable $progress, string $status): void
    {
        if ($progress && $status !== 'error') {
            sleep(self::INSTALL_STEP_DELAY_SECONDS);
        }
    }

    public function performInstallation(
        string $httpPort,
        string $httpsPort,
        string $organization,
        string $image,
        array $input,
        bool $noStart,
        ?callable $progress = null,
        ?string $resumeFromStep = null,
        bool $isUpgrade = false
    ): void {
        $isCLI = php_sapi_name() === 'cli';
        $useExistingConfig = !empty(getenv('APPWRITE_INSTALLER_LOCAL'))
            && file_exists($this->path . '/docker-compose.yml')
            && file_exists($this->path . '/.env');

        if (getenv('APPWRITE_INSTALLER_LOCAL')) {
            $organization = 'appwrite';
            $image = 'appwrite';
        }

        $templateForCompose = new View(__DIR__ . '/../../../../app/views/install/compose.phtml');
        $templateForEnv = new View(__DIR__ . '/../../../../app/views/install/env.phtml');

        $database = $input['_APP_DB_ADAPTER'] ?? 'mongodb';

        $version = \defined('APP_VERSION_STABLE') ? APP_VERSION_STABLE : 'latest';
        if (getenv('APPWRITE_INSTALLER_LOCAL')) {
            $version = 'local';
        }

        $templateForCompose
            ->setParam('httpPort', $httpPort)
            ->setParam('httpsPort', $httpsPort)
            ->setParam('version', $version)
            ->setParam('organization', $organization)
            ->setParam('image', $image)
            ->setParam('database', $database);

        $templateForEnv->setParam('vars', $input);

        $steps = ['docker-compose', 'env-vars', 'docker-containers'];
        $startIndex = 0;
        if ($resumeFromStep !== null) {
            $resumeIndex = array_search($resumeFromStep, $steps, true);
            if ($resumeIndex !== false) {
                $startIndex = $resumeIndex;
            }
        }

        $currentStep = null;

        $messages = $isUpgrade ? [
            'config-files' => [
                'start' => 'Updating configuration files...',
                'done' => 'Configuration files updated',
            ],
            'docker-compose' => [
                'start' => 'Updating Docker Compose file...',
                'done' => 'Docker Compose file updated',
            ],
            'env-vars' => [
                'start' => 'Updating environment variables...',
                'done' => 'Environment variables updated',
            ],
            'docker-containers' => [
                'start' => 'Restarting Docker containers...',
                'done' => 'Docker containers restarted',
            ],
        ] : [
            'config-files' => [
                'start' => 'Creating configuration files...',
                'done' => 'Configuration files created',
            ],
            'docker-compose' => [
                'start' => 'Generating Docker Compose file...',
                'done' => 'Docker Compose file generated',
            ],
            'env-vars' => [
                'start' => 'Configuring environment variables...',
                'done' => 'Environment variables configured',
            ],
            'docker-containers' => [
                'start' => 'Starting Docker containers...',
                'done' => 'Docker containers started',
            ],
        ];

        try {
            if ($startIndex <= 1) {
                $this->reportProgress($progress, 'config-files', 'in-progress', $messages['config-files']['start']);
                $this->delayProgress($progress, 'in-progress');
            }

            if ($startIndex <= 0) {
                $currentStep = 'docker-compose';
                $this->reportProgress($progress, 'docker-compose', 'in-progress', $messages['docker-compose']['start']);
                $this->delayProgress($progress, 'in-progress');

                if (!$useExistingConfig) {
                    if (!\file_put_contents($this->path . '/docker-compose.yml', $templateForCompose->render(false))) {
                        throw new \Exception('Failed to save Docker Compose file');
                    }
                }

                $this->reportProgress($progress, 'docker-compose', 'completed', $messages['docker-compose']['done']);
            }

            if ($startIndex <= 1) {
                $currentStep = 'env-vars';
                $this->reportProgress($progress, 'env-vars', 'in-progress', $messages['env-vars']['start']);
                $this->delayProgress($progress, 'in-progress');

                if (!$useExistingConfig) {
                    if (!\file_put_contents($this->path . '/.env', $templateForEnv->render(false))) {
                        throw new \Exception('Failed to save environment variables file');
                    }
                }

                $this->reportProgress($progress, 'env-vars', 'completed', $messages['env-vars']['done']);
                $this->reportProgress($progress, 'config-files', 'completed', $messages['config-files']['done']);
            }

            if ($database === 'mongodb' && !$useExistingConfig) {
                $mongoEntrypoint = __DIR__ . '/../../../../mongo-entrypoint.sh';

                if (file_exists($mongoEntrypoint)) {
                    copy($mongoEntrypoint, $this->path . '/mongo-entrypoint.sh');
                }
            }

            if (!$noStart && $startIndex <= 2) {
                $currentStep = 'docker-containers';
                $this->reportProgress($progress, 'docker-containers', 'in-progress', $messages['docker-containers']['start']);
                $this->delayProgress($progress, 'in-progress');
                $envVars = getenv();
                if (!is_array($envVars)) {
                    $envVars = [];
                }
                if (!$useExistingConfig) {
                    foreach ($input as $key => $value) {
                        if ($value === null || $value === '') {
                            continue;
                        }
                        if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
                            throw new \Exception('Invalid environment variable name');
                        }
                        $envVars[$key] = (string) $value;
                    }
                }

                if ($isCLI) {
                    Console::log("Running \"docker compose up -d --remove-orphans --renew-anon-volumes\"");
                }

                $command = [
                    'docker',
                    'compose',
                    '--project-directory',
                    $this->path,
                    'up',
                    '-d',
                    '--remove-orphans',
                    '--renew-anon-volumes'
                ];
                $descriptorSpec = [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];
                $process = \proc_open($command, $descriptorSpec, $pipes, null, $envVars);
                if (!is_resource($process)) {
                    throw new \Exception('Failed to start Docker Compose');
                }
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                if (is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                if (is_resource($pipes[2])) {
                    fclose($pipes[2]);
                }
                $exit = \proc_close($process);

                if ($exit !== 0) {
                    $output = trim(($stderr ?: '') . ($stdout ?: ''));
                    $previous = $output !== '' ? new \RuntimeException($output) : null;
                    throw new \RuntimeException('Failed to start containers', 0, $previous);
                }

                $this->reportProgress($progress, 'docker-containers', 'completed', $messages['docker-containers']['done']);

                if ($isCLI) {
                    Console::success('Appwrite installed successfully');
                }
            } else {
                if ($isCLI) {
                    Console::success('Installation files created. Run "docker compose up -d" to start Appwrite');
                }
            }
        } catch (\Throwable $e) {
            if ($currentStep) {
                $details = [
                    'trace' => $e->getTraceAsString()
                ];
                $previous = $e->getPrevious();
                if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
                    $details['output'] = $previous->getMessage();
                }
                $this->reportProgress($progress, $currentStep, 'error', $e->getMessage(), $details);
            }
            throw $e;
        }
    }
}
