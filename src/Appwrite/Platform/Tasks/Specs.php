<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Specification\Format\OpenAPI3;
use Appwrite\SDK\Specification\Format\Swagger2;
use Appwrite\SDK\Specification\Specification;
use Appwrite\Utopia\Request as AppwriteRequest;
use Appwrite\Utopia\Response as AppwriteResponse;
use Exception;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Http\Http;
use Utopia\Http\Request as UtopiaRequest;
use Utopia\Http\Response as UtopiaResponse;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Specs extends Action
{
    public function __construct()
    {
        $this
            ->desc('Generate Appwrite API specifications')
            ->param('version', 'latest', new Text(16), 'Spec version', true)
            ->param('mode', 'normal', new WhiteList(['normal', 'mocks']), 'Spec Mode', true)
            ->param('git', null, new Nullable(new WhiteList(['yes', 'no'])), 'Should we push to the specs repo?', optional: true)
            ->param('message', null, new Nullable(new Text(256)), 'Commit Message', optional: true)
            ->param('branch', null, new Nullable(new Text(256)), 'Target branch for PR (defaults to main)', optional: true)
            ->callback($this->action(...));
    }

    public static function getName(): string
    {
        return 'specs';
    }

    public function getRequest(): UtopiaRequest
    {
        return new AppwriteRequest(new SwooleRequest());
    }

    public function getResponse(): UtopiaResponse
    {
        return new AppwriteResponse(new SwooleResponse());
    }

    protected function getFormatInstance(string $format, array $arguments)
    {
        return match ($format) {
            'swagger2' => new Swagger2(...$arguments),
            'open-api3' => new OpenAPI3(...$arguments),
            default => throw new Exception('Format not found: ' . $format)
        };
    }

    /**
     * Platforms
     *
     * @return array<string>
     */
    public static function getPlatforms(): array
    {
        return [
            APP_SDK_PLATFORM_CLIENT,
            APP_SDK_PLATFORM_SERVER,
            APP_SDK_PLATFORM_CONSOLE,
        ];
    }

    /**
     * Platforms to include in PR creation.
     * Override in a subclass to exclude specific platforms.
     *
     * @return array<string>
     */
    public static function getPlatformsForPR(): array
    {
        return static::getPlatforms();
    }

    /**
     * Build the CLI command used to regenerate SDK examples.
     * Override in a subclass to customise flags (platform, sdk, mode, etc.).
     *
     * @param string $version Spec version being generated
     * @return string Shell command string (including 2>&1 redirect)
     */
    protected function getSdksCommand(string $version): string
    {
        $cli = \realpath(__DIR__ . '/../../../../app') . '/cli.php';

        return 'php ' . \escapeshellarg($cli)
            . ' sdks --platform=* --sdk=* --version=' . \escapeshellarg($version)
            . ' --git=no --mode=examples 2>&1';
    }

    /**
     * Number of authentication methods supported by each platform
     * client: 1 (Session or JWT), server: 2 (Key and JWT), console: 1 (Admin)
     *
     * @return array{client: int, console: int, server: int}
     */
    protected function getAuthCounts(): array
    {
        return [
            'client' => 1,
            'server' => 2,
            'console' => 1,
        ];
    }

    /**
     * Keys for each platform
     *
     * @return array{client: array, server: array, console: array}
     */
    protected function getKeys(): array
    {
        return [
            APP_SDK_PLATFORM_CLIENT => [
                'Project' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Project',
                    'description' => 'Your project ID',
                    'in' => 'header',
                ],
                'JWT' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-JWT',
                    'description' => 'Your secret JSON Web Token',
                    'in' => 'header',
                ],
                'Locale' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Locale',
                    'description' => '',
                    'in' => 'header',
                ],
                'Session' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Session',
                    'description' => 'The user session to authenticate with',
                    'in' => 'header',
                ],
                'DevKey' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Dev-Key',
                    'description' => 'Your secret dev API key',
                    'in' => 'header',
                ]
            ],
            APP_SDK_PLATFORM_SERVER => [
                'Project' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Project',
                    'description' => 'Your project ID',
                    'in' => 'header',
                ],
                'Key' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Key',
                    'description' => 'Your secret API key',
                    'in' => 'header',
                ],
                'JWT' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-JWT',
                    'description' => 'Your secret JSON Web Token',
                    'in' => 'header',
                ],
                'Locale' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Locale',
                    'description' => '',
                    'in' => 'header',
                ],
                'Session' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Session',
                    'description' => 'The user session to authenticate with',
                    'in' => 'header',
                ],
                'ForwardedUserAgent' => [
                    'type' => 'apiKey',
                    'name' => 'X-Forwarded-User-Agent',
                    'description' => 'The user agent string of the client that made the request',
                    'in' => 'header',
                ],
            ],
            APP_SDK_PLATFORM_CONSOLE => [
                'Project' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Project',
                    'description' => 'Your project ID',
                    'in' => 'header',
                ],
                'Key' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Key',
                    'description' => 'Your secret API key',
                    'in' => 'header',
                ],
                'JWT' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-JWT',
                    'description' => 'Your secret JSON Web Token',
                    'in' => 'header',
                ],
                'Locale' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Locale',
                    'description' => '',
                    'in' => 'header',
                ],
                'Mode' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Mode',
                    'description' => '',
                    'in' => 'header',
                ],
                'Cookie' => [
                    'type' => 'apiKey',
                    'name' => 'Cookie',
                    'description' => 'The user cookie to authenticate with',
                    'in' => 'header',
                ],
            ],
        ];
    }

    public function getSDKPlatformsForRouteSecurity(array $routeSecurity): array
    {
        $sdkPlatforms = [];
        foreach ($routeSecurity as $value) {
            switch ($value) {
                case AuthType::SESSION:
                    $sdkPlatforms[] = APP_SDK_PLATFORM_CLIENT;
                    break;
                case AuthType::JWT:
                case AuthType::KEY:
                    $sdkPlatforms[] = APP_SDK_PLATFORM_SERVER;
                    break;
                case AuthType::ADMIN:
                    $sdkPlatforms[] = APP_SDK_PLATFORM_CONSOLE;
                    break;
            }
        }

        return $sdkPlatforms;
    }

    public function action(string $version, string $mode, ?string $git, ?string $message, ?string $branch): void
    {
        if (\is_null($git)) {
            $git = Console::confirm('Should we push specs to the appwrite/specs repo? (yes/no)');
        }

        if ($git === 'yes' && \is_null($message)) {
            $message = Console::confirm('Please enter your commit message:');
        }

        if (\is_null($branch)) {
            $branch = 'main';
        }

        $appRoutes = Http::getRoutes();

        /** @var AppwriteResponse $response */
        $response = $this->getResponse();

        $mocks = ($mode === 'mocks');

        // Mock dependencies
        Http::setResource('request', fn () => $this->getRequest());
        Http::setResource('response', fn () => $response);
        Http::setResource('dbForPlatform', fn () => new Database(new MySQL(''), new Cache(new None())));
        Http::setResource('dbForProject', fn () => new Database(new MySQL(''), new Cache(new None())));

        $platforms = static::getPlatforms();
        $authCounts = $this->getAuthCounts();
        $keys = $this->getKeys();

        $generatedFiles = [];

        foreach ($platforms as $platform) {
            $routes = [];
            $models = [];
            $services = [];

            foreach ($appRoutes as $key => $method) {
                foreach ($method as $route) {
                    $sdks = $route->getLabel('sdk', false);

                    if (empty($sdks)) {
                        continue;
                    }

                    if (!\is_array($sdks)) {
                        $sdks = [$sdks];
                    }

                    foreach ($sdks as $sdk) {
                        /** @var Method $sdk */
                        $hide = $sdk->isHidden();

                        if ($hide === true || (\is_array($hide) && \in_array($platform, $hide))) {
                            continue;
                        }

                        $routeSecurity = $sdk->getAuth();
                        $sdkPlatforms = $this->getSDKPlatformsForRouteSecurity($routeSecurity);

                        if (!$route->getLabel('docs', true)) {
                            continue;
                        }

                        if ($route->getLabel('mock', false) && !$mocks) {
                            continue;
                        }

                        if (!$route->getLabel('mock', false) && $mocks) {
                            continue;
                        }

                        if (empty($sdk->getNamespace())) {
                            continue;
                        }

                        if (!\in_array($platform, $sdkPlatforms)) {
                            continue;
                        }

                        $routes[] = $route;
                    }
                }
            }

            foreach (Config::getParam('services', []) as $service) {
                if (
                    !isset($service['docs']) // Skip service if not part of the public API
                    || !isset($service['sdk'])
                    || !$service['docs']
                    || !$service['sdk']
                ) {
                    continue;
                }

                // Check if current platform is included in service's platforms
                if (!\in_array($platform, $service['platforms'] ?? [])) {
                    continue;
                }

                $services[] = [
                    'name' => $service['key'] ?? '',
                    'description' => $service['subtitle'] ?? '',
                ];
            }

            $models = $response->getModels();

            foreach ($models as $key => $value) {
                if ($platform !== APP_SDK_PLATFORM_CONSOLE && !$value->isPublic()) {
                    unset($models[$key]);
                }
            }

            $arguments = [
                new Http('UTC'),
                $services,
                $routes,
                $models,
                $keys[$platform],
                $authCounts[$platform] ?? 0,
                $platform
            ];

            foreach (['swagger2', 'open-api3'] as $format) {
                $formatInstance = $this->getFormatInstance($format, $arguments);
                $specs = new Specification($formatInstance);
                $endpoint = System::getEnv('_APP_HOME', '[HOSTNAME]');
                $email = System::getEnv('_APP_SYSTEM_TEAM_EMAIL', APP_EMAIL_TEAM);

                $formatInstance
                    ->setParam('name', APP_NAME)
                    ->setParam('description', 'Appwrite backend as a service cuts up to 70% of the time and costs required for building a modern application. We abstract and simplify common development tasks behind a REST APIs, to help you develop your app in a fast and secure way. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)')
                    ->setParam('endpoint', 'https://cloud.appwrite.io/v1')
                    ->setParam('endpoint.docs', 'https://<REGION>.cloud.appwrite.io/v1')
                    ->setParam('version', APP_VERSION_STABLE)
                    ->setParam('terms', $endpoint . '/policy/terms')
                    ->setParam('support.email', $email)
                    ->setParam('support.url', $endpoint . '/support')
                    ->setParam('contact.name', APP_NAME . ' Team')
                    ->setParam('contact.email', $email)
                    ->setParam('contact.url', $endpoint . '/support')
                    ->setParam('license.name', 'BSD-3-Clause')
                    ->setParam('license.url', 'https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE')
                    ->setParam('docs.description', 'Full API docs, specs and tutorials')
                    ->setParam('docs.url', $endpoint . '/docs');

                if ($mocks) {
                    $path = __DIR__ . '/../../../../app/config/specs/' . $format . '-mocks-' . $platform . '.json';

                    if (!file_put_contents($path, json_encode($specs->parse(), JSON_PRETTY_PRINT))) {
                        throw new Exception('Failed to save mocks spec file: ' . $path);
                    }

                    $generatedFiles[] = realpath($path);
                    Console::success('Saved mocks spec file: ' . realpath($path));

                    continue;
                }

                $path = __DIR__ . '/../../../../app/config/specs/' . $format . '-' . $version . '-' . $platform . '.json';

                if (!file_put_contents($path, json_encode($specs->parse(), JSON_PRETTY_PRINT))) {
                    throw new Exception('Failed to save spec file: ' . $path);
                }

                $generatedFiles[] = realpath($path);
                Console::success('Saved spec file: ' . realpath($path));
            }
        }

        if ($git === 'yes') {
            $gitUrl = 'git@github.com:appwrite/specs.git';
            $gitRepoName = 'appwrite/specs';
            $gitBranch = 'feat-' . $version . '-specs';
            $repoBranch = $branch;
            $target = \realpath(__DIR__ . '/../../../../app') . '/sdks/git/specs/';
            $examplesDir = \realpath(__DIR__ . '/../../../..') . '/docs/examples/' . $version;

            Console::info("Cloning {$gitRepoName} into {$target}...");

            \exec('rm -rf ' . $target . ' && \
                mkdir -p ' . $target . ' && \
                cd ' . $target . ' && \
                git init && \
                git config core.ignorecase false && \
                git config pull.rebase false && \
                git remote add origin ' . $gitUrl . ' && \
                git fetch origin && \
                (git checkout -f ' . $repoBranch . ' 2>/dev/null || git checkout -b ' . $repoBranch . ') && \
                git pull origin ' . $repoBranch . ' && \
                (git checkout -f ' . $gitBranch . ' 2>/dev/null || git checkout -b ' . $gitBranch . ') && \
                (git fetch origin ' . $gitBranch . ' 2>/dev/null || git push -u origin ' . $gitBranch . ') && \
                git reset --hard origin/' . $gitBranch . ' 2>/dev/null || true
            ');

            // Copy generated spec files into specs/{version}/ subdirectory
            $prPlatforms = static::getPlatformsForPR();
            $prFiles = \array_filter(
                $generatedFiles,
                fn (string $file) => \in_array(
                    \substr(\basename($file, '.json'), \strrpos(\basename($file, '.json'), '-') + 1),
                    $prPlatforms,
                    true
                )
            );

            $specsSubDir = $mocks ? 'mocks' : $version;
            \exec('mkdir -p ' . \escapeshellarg("{$target}/specs/{$specsSubDir}"));
            foreach ($prFiles as $file) {
                $fileName = \basename($file);
                \exec('cp ' . \escapeshellarg($file) . ' ' . \escapeshellarg("{$target}/specs/{$specsSubDir}/{$fileName}"));
                Console::success("Copied spec file to repo: specs/{$specsSubDir}/{$fileName}");
            }

            // Regenerate SDK examples for this version
            Console::info("Regenerating SDK examples for version {$version}...");
            $sdksCommand = $this->getSdksCommand($version);
            \exec($sdksCommand, $sdksOutput, $sdksReturnCode);

            if ($sdksReturnCode !== 0) {
                Console::warning("SDK examples generation returned non-zero exit code: {$sdksReturnCode}");
                Console::warning(\implode("\n", $sdksOutput));
            } else {
                Console::success("Regenerated SDK examples for version {$version}");
            }

            // Copy SDK examples for this version
            if (\is_dir($examplesDir)) {
                \exec('mkdir -p ' . \escapeshellarg("{$target}/examples/{$version}") . ' && \
                    cp -r ' . \escapeshellarg($examplesDir) . '/. ' . \escapeshellarg("{$target}/examples/{$version}/"));
                Console::success("Copied SDK examples for version {$version} to repo: examples/{$version}/");
            } else {
                Console::warning("No SDK examples found at: {$examplesDir}. Skipping examples copy.");
            }

            // Git add, commit, and push
            \exec('cd ' . $target . ' && \
                git add -A && \
                git commit -m "' . \addslashes($message) . '" && \
                git push -u origin ' . $gitBranch . '
            ');

            Console::success("Pushed specs to {$gitRepoName} on branch {$gitBranch}");

            // Create or update PR
            $prTitle = "feat: API specs update for version {$version}";
            $prBody = "This PR contains API specification updates and SDK examples for version {$version}.";

            $prCommand = 'cd ' . $target . ' && \
                gh pr create \
                --repo "' . $gitRepoName . '" \
                --title "' . $prTitle . '" \
                --body "' . $prBody . '" \
                --base "' . $repoBranch . '" \
                --head "' . $gitBranch . '" \
                2>&1';

            $prUrl = '';
            \exec($prCommand, $prOutput);
            $prOutput = \implode("\n", $prOutput);

            if (\str_contains($prOutput, 'already exists')) {
                Console::warning("PR already exists for branch {$gitBranch}");
                // Try to get the existing PR URL
                \exec('cd ' . $target . ' && gh pr view --repo "' . $gitRepoName . '" --json url -q .url 2>&1', $existingPrOutput);
                $prUrl = \trim(\implode("\n", $existingPrOutput));
            } else {
                $prUrl = \trim($prOutput);
            }

            // Clean up temp directory
            \exec('chmod -R u+w ' . $target . ' && rm -rf ' . $target);
            Console::success("Removed temp directory '{$target}'");

            if (!empty($prUrl)) {
                Console::log('');
                Console::log('Pull Request Summary');
                Console::log("Specs PR: {$prUrl}");
                Console::log('');
            }
        }
    }
}
