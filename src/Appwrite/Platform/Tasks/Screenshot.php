<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Platform\Action;
use Utopia\Validator\Text;

class Screenshot extends Action
{
    public static function getName(): string
    {
        return 'screenshot';
    }

    public function __construct()
    {
        $this
            ->desc('Create Site template screenshot')
            ->param('templateId', '', new Text(128), 'Template ID.')
            ->callback($this->action(...));
    }

    public function action(string $templateId): void
    {
        $templates = Config::getParam('templates-site', []);

        $allowedTemplates = \array_filter($templates, function ($item) use ($templateId) {
            return $item['key'] === $templateId;
        });
        $template = \array_shift($allowedTemplates);

        if (empty($template)) {
            throw new \Exception("Template {$templateId} not found. Find correct ID in app/config/templates/site.php");
        }

        Console::info("Found: " . $template['name']);

        $client = new Client();
        $client->setEndpoint('http://localhost/v1');
        $client->addHeader('origin', 'http://localhost');

        // Register
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';

        Console::info("Email: {$email}");
        Console::info("Pass: {$password}");

        $user = $client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
        ]);

        if ($user['headers']['status-code'] !== 201) {
            Console::error(\json_encode($user));
            throw new \Exception("Failed to register user");
        }

        Console::info("User created");

        // Login
        $session = $client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        if ($session['headers']['status-code'] !== 201) {
            Console::error(\json_encode($session));
            throw new \Exception("Failed to login user");
        }

        Console::info("Session created");

        $secret = $session['cookies']['a_session_console'];
        $cookieConsole = 'a_session_console=' . $secret;

        // Create organization
        $team = $client->call(Client::METHOD_POST, '/teams', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => $cookieConsole
        ], [
            'teamId' => ID::unique(),
            'name' => 'Demo Project Team',
        ]);

        if ($team['headers']['status-code'] !== 201) {
            Console::error(\json_encode($team));
            throw new \Exception("Failed to create team");
        }

        Console::info("Team created");

        $projectName = 'Demo Project';
        $projectId = ID::unique();

        // Create project
        $project = $client->call(Client::METHOD_POST, '/projects', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => $cookieConsole
        ], [
            'projectId' => $projectId,
            'region' => 'default',
            'name' => $projectName,
            'teamId' => $team['body']['$id'],
            'description' => 'Demo Project Description',
            'url' => 'https://appwrite.io',
        ]);

        if ($project['headers']['status-code'] !== 201) {
            Console::error(\json_encode($project));
            throw new \Exception("Failed to create project");
        }

        Console::info("Project created");

        $projectId = $project['body']['$id'];

        $framework = $template['frameworks'][0];

        // Create site
        $site = $client->call(Client::METHOD_POST, '/sites', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
            'cookie' => $cookieConsole
        ], [
            'siteId' => ID::unique(),
            'name' => $template["name"],
            'framework' => $framework['key'],
            'adapter' => $framework['adapter'],
            'buildCommand' => $framework['buildCommand'] ?? '',
            'buildRuntime' => $framework['buildRuntime'],
            'fallbackFile' => $framework['fallbackFile'] ?? '',
            'installCommand' => $framework['installCommand'] ?? '',
            'outputDirectory' => $framework['outputDirectory'] ?? '',
            'providerRootDirectory' => $framework['providerRootDirectory'],
            'timeout' => 30
        ]);

        if ($site['headers']['status-code'] !== 201) {
            Console::error(\json_encode($site));
            throw new \Exception("Failed to create site");
        }

        Console::info("Site created");

        $siteId = $site['body']['$id'];

        // Create variables
        if (!empty($template['variables'] ?? [])) {
            foreach ($template['variables'] as $variable) {
                if (empty($variable['value'] ?? '')) {
                    if (($variable['required'] ?? false) === true) {
                        throw new \Exception("Missing required variable: {$variable['name']}");
                    }

                    continue;
                }

                $value = $variable['value'];
                $value = \str_replace('{projectName}', $projectName, $value);
                $value = \str_replace('{projectId}', $projectId, $value);
                $value = \str_replace('{apiEndpoint}', 'http://localhost/v1', $value);

                $response = $client->call(Client::METHOD_POST, '/sites/' . $siteId . '/variables', [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-mode' => 'admin',
                    'cookie' => $cookieConsole
                ], [
                    'key' => $variable['name'],
                    'value' => $value
                ]);

                if ($response['headers']['status-code'] !== 201) {
                    Console::error(\json_encode($response));
                    throw new \Exception("Failed to create variable");
                }
            }

            Console::info("Variables created");
        }

        // Create deployment
        $deployment = $client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments/template', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
            'cookie' => $cookieConsole
        ], [
            'owner' => $template['providerOwner'],
            'repository' => $template['providerRepositoryId'],
            'rootDirectory' => $framework['providerRootDirectory'],
            'version' => $template['providerVersion'],
            'activate' => true,
        ]);

        if ($deployment['headers']['status-code'] !== 202) {
            Console::error(\json_encode($deployment));
            throw new \Exception("Failed to create deployment");
        }

        Console::info("Deployment created");

        $deploymentId = $deployment['body']['$id'];

        // Await screenshot
        $attempts = 60; // 5 min
        $sleep = 5;

        $idLight = '';
        $idDark = '';

        $slowTemplates = [
            'starter-for-react-native',
            'playground-for-react-native'
        ];
        if (\in_array($templateId, $slowTemplates)) {
            Console::warning("Build for this template is slow, increasing waiting time ...");
            $attempts = 180; // 15 min
        }

        Console::log("Awaiting deployment (every $sleep seconds, $attempts attempts)");

        for ($i = 0; $i < $attempts; $i++) {
            $deployment = $client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-mode' => 'admin',
                'cookie' => $cookieConsole
            ]);

            if ($deployment['headers']['status-code'] !== 200) {
                Console::error(\json_encode($deployment));
                throw new \Exception("Failed to get deployment");
            }

            if ($deployment['body']['status'] === 'failed') {
                Console::error(\json_encode($deployment));
                throw new \Exception("Deployment build failed");
            }

            if ($deployment['body']['status'] !== 'ready') {
                Console::log("Deployment not ready yet, status: " . $deployment['body']['status']);
                \sleep($sleep);
                continue;
            }


            if (empty($deployment['body']['screenshotLight'])) {
                Console::log("Light screenshot not available yet");
                \sleep($sleep);
                continue;
            }

            if (empty($deployment['body']['screenshotDark'])) {
                Console::log("Dark screenshot not available yet");
                \sleep($sleep);
                continue;
            }

            $idLight = $deployment['body']['screenshotLight'];
            $idDark = $deployment['body']['screenshotDark'];
            break;
        }

        if (empty($idLight) || empty($idDark)) {
            Console::error(\json_encode($deployment));
            throw new \Exception("Failed to get deployment screenshot");
        }

        Console::info("Screenshots created");

        $themes = [
            [ 'fileId' => $idLight, 'suffix' => 'light' ],
            [ 'fileId' => $idDark, 'suffix' => 'dark' ]
        ];

        foreach ($themes as $theme) {
            $file = $client->call(Client::METHOD_GET, '/storage/buckets/screenshots/files/' . $theme['fileId'] . '/download', [
                'x-appwrite-project' => 'console',
                'cookie' => $cookieConsole
            ]);

            if ($file['headers']['status-code'] !== 200) {
                Console::error(\json_encode($file));
                throw new \Exception("Failed to download {$theme['suffix']} screenshot");
            }

            $path = "/usr/src/code/public/images/sites/templates/{$template['key']}-{$theme['suffix']}.png";

            if (!\file_put_contents($path, $file['body'])) {
                throw new \Exception("Failed to save {$theme['suffix']} screenshot");
            }
        }

        Console::success("Screenshots saved");
    }
}
