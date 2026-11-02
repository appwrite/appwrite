<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Network\Validator\PublicURL;
use Appwrite\Network\Validator\Redirect;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Specification\Format\OpenAPI3;
use Appwrite\SDK\Specification\Specification;
use Appwrite\Utopia\Request as AppwriteRequest;
use Appwrite\Utopia\Response as AppwriteResponse;
use Exception;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\Client\Destinations\Anywhere;
use Utopia\Config\Config;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\DI\Container;
use Utopia\DNS\Lookup\Recursive;
use Utopia\Http\Http;
use Utopia\Http\Request as UtopiaRequest;
use Utopia\Http\Response as UtopiaResponse;
use Utopia\OpenAPI\Model\ParameterLocation;
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
     * @return Command|string A prepared command, or a shell string from an override that predates it
     */
    protected function getSdksCommand(string $version): Command|string
    {
        $cli = \realpath(__DIR__ . '/../../../../app') . '/cli.php';

        return (new Command('php'))
            ->argument($cli)
            ->argument('sdks')
            ->argument('--platform=*')
            ->argument('--sdk=*')
            ->argument('--version=' . $version)
            ->argument('--git=no')
            ->argument('--mode=examples');
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
                'ProjectPath' => [
                    'location' => ParameterLocation::PATH->value,
                    'param' => 'project_id',
                    'config' => 'project',
                ],
                'JWT' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-JWT',
                    'description' => 'Your secret JSON Web Token',
                    'in' => 'header',
                ],
                'Bearer' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'The OAuth access token to authenticate with',
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
                'Cookie' => [
                    'type' => 'apiKey',
                    'name' => 'Cookie',
                    'description' => 'The user cookie to authenticate with. Used by SDKs that forward an incoming Cookie header in server-side runtimes.',
                    'in' => 'header',
                ],
                'ImpersonateUserId' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Id',
                    'description' => 'Impersonate a user by ID',
                    'in' => 'header',
                ],
                'ImpersonateUserEmail' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Email',
                    'description' => 'Impersonate a user by email',
                    'in' => 'header',
                ],
                'ImpersonateUserPhone' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Phone',
                    'description' => 'Impersonate a user by phone',
                    'in' => 'header',
                ],
            ],
            APP_SDK_PLATFORM_SERVER => [
                'Project' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Project',
                    'description' => 'Your project ID',
                    'in' => 'header',
                ],
                'ProjectPath' => [
                    'location' => ParameterLocation::PATH->value,
                    'param' => 'project_id',
                    'config' => 'project',
                ],
                'Key' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Key',
                    'description' => 'Your secret API key',
                    'in' => 'header',
                ],
                'Organization' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Organization',
                    'description' => 'Your organization ID',
                    'in' => 'header',
                ],
                'JWT' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-JWT',
                    'description' => 'Your secret JSON Web Token',
                    'in' => 'header',
                ],
                'Bearer' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'The OAuth access token to authenticate with',
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
                'Cookie' => [
                    'type' => 'apiKey',
                    'name' => 'Cookie',
                    'description' => 'The user cookie to authenticate with. Used by SDKs that forward an incoming Cookie header in server-side runtimes.',
                    'in' => 'header',
                ],
                'ImpersonateUserId' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Id',
                    'description' => 'Impersonate a user by ID',
                    'in' => 'header',
                ],
                'ImpersonateUserEmail' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Email',
                    'description' => 'Impersonate a user by email',
                    'in' => 'header',
                ],
                'ImpersonateUserPhone' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Phone',
                    'description' => 'Impersonate a user by phone',
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
                'ProjectPath' => [
                    'location' => ParameterLocation::PATH->value,
                    'param' => 'project_id',
                    'config' => 'project',
                ],
                'Key' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Key',
                    'description' => 'Your secret API key',
                    'in' => 'header',
                ],
                'Organization' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Organization',
                    'description' => 'Your organization ID',
                    'in' => 'header',
                ],
                'JWT' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-JWT',
                    'description' => 'Your secret JSON Web Token',
                    'in' => 'header',
                ],
                'Bearer' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'The OAuth access token to authenticate with',
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
                    'description' => 'The user cookie to authenticate with. Used by SDKs that forward an incoming Cookie header in server-side runtimes.',
                    'in' => 'header',
                ],
                'Session' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Session',
                    'description' => 'The user session to authenticate with',
                    'in' => 'header',
                ],
                'ImpersonateUserId' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Id',
                    'description' => 'Impersonate a user by ID',
                    'in' => 'header',
                ],
                'ImpersonateUserEmail' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Email',
                    'description' => 'Impersonate a user by email',
                    'in' => 'header',
                ],
                'ImpersonateUserPhone' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Impersonate-User-Phone',
                    'description' => 'Impersonate a user by phone',
                    'in' => 'header',
                ],
            ],
        ];
    }

    public function getSDKPlatformsForRouteSecurity(array $routeSecurity): array
    {
        $platforms = [];
        foreach ($routeSecurity as $auth) {
            $platform = $auth instanceof AuthType ? $auth->getPlatform() : null;
            if ($platform !== null) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
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

        // Mock dependencies needed by param validator injections in route definitions
        $specsContainer = new Container();
        $specsContainer->set('request', fn () => $this->getRequest());
        $specsContainer->set('response', fn () => $response);
        $mockDatabase = fn () => new Database(new MySQL(new \stdClass()), new Cache(new None()));
        $specsContainer->set('dbForPlatform', $mockDatabase);
        $specsContainer->set('dbForProject', $mockDatabase);
        $specsContainer->set('redirectValidator', fn () => new Redirect([], []));
        $specsContainer->set('publicURL', fn () => new PublicURL(new PublicHostname(new Anywhere(), new Recursive(['127.0.0.1']))));
        $specsContainer->set('project', fn () => new Document([]));
        $specsContainer->set('passwordsDictionary', fn () => []);
        $specsContainer->set('localeCodes', fn () => \array_map(fn ($locale) => $locale['code'], Config::getParam('locale-codes', [])));
        $specsContainer->set('plan', fn () => []);

        $platforms = \array_values(static::getPlatforms());
        $authCounts = $this->getAuthCounts();
        $platformKeys = $this->getKeys();
        $keys = [];

        foreach ($platforms as $platform) {
            $keys[$platform] = $platformKeys[$platform] ?? [];
        }

        /** @var array<string, string|null> $generatedFiles Spec file path to its platform, null for the canonical document. */
        $generatedFiles = [];
        $endpoint = System::getEnv('_APP_HOME', 'https://appwrite.io');
        $email = System::getEnv('_APP_SYSTEM_TEAM_EMAIL', 'team@appwrite.io');
        $specsDir = __DIR__ . '/../../../../app/config/specs';

        if (!is_dir($specsDir) && !@mkdir($specsDir, 0755, true) && !is_dir($specsDir)) {
            throw new Exception('Failed to create specs directory: ' . $specsDir);
        }

        // Resolve full auth arrays through the active task (including subclass platforms).
        foreach ($appRoutes as $method) {
            foreach ($method as $route) {
                $sdks = $route->getLabel('sdk', []);
                foreach (\is_array($sdks) ? $sdks : [$sdks] as $sdk) {
                    if ($sdk instanceof Method) {
                        $sdk->setPlatforms($this->getSDKPlatformsForRouteSecurity($sdk->getAuth()));
                    }
                }
            }
        }

        // One document per platform, then the canonical document (null) covering every platform.
        foreach ([...$platforms, null] as $platform) {
            $routes = [];
            $models = [];
            $services = [];
            $routeNamespaces = [];

            foreach ($appRoutes as $key => $method) {
                foreach ($method as $route) {
                    if (!$route->getLabel('docs', true) || (bool) $route->getLabel('mock', false) !== $mocks) {
                        continue;
                    }

                    $sdks = $route->getLabel('sdk', false);

                    if (empty($sdks)) {
                        continue;
                    }

                    if (!\is_array($sdks)) {
                        $sdks = [$sdks];
                    }

                    foreach ($sdks as $sdk) {
                        $sdkPlatforms = $sdk->getPlatforms();

                        if ($platform === null ? $sdkPlatforms === [] : !\in_array($platform, $sdkPlatforms, true)) {
                            continue;
                        }

                        $routes[\spl_object_id($route)] = $route;
                        $routeNamespaces[$sdk->getNamespace()] = true;
                    }
                }
            }

            /**
             * Tag names must match Method namespaces (path tags), e.g. tablesDB.
             * Service config keys stay lowercase (tablesdb); descriptions resolve
             * case-insensitively from services.php.
             */
            $serviceDescriptions = [];
            $configuredServices = [];

            foreach (Config::getParam('services', []) as $service) {
                $serviceKey = $service['key'] ?? '';
                if ($serviceKey === '') {
                    continue;
                }

                $serviceDescriptions[\strtolower($serviceKey)] = $service['subtitle'] ?? '';

                if (
                    !isset($service['docs']) // Skip service if not part of the public API
                    || !isset($service['sdk'])
                    || !$service['docs']
                    || !$service['sdk']
                ) {
                    continue;
                }

                $servicePlatforms = $service['platforms'] ?? [];

                if ($platform === null ? \array_intersect($servicePlatforms, $platforms) === [] : !\in_array($platform, $servicePlatforms)) {
                    continue;
                }

                $configuredServices[$serviceKey] = $service['subtitle'] ?? '';
            }

            $seenServices = [];

            foreach (\array_keys($routeNamespaces) as $namespace) {
                $services[] = [
                    'name' => $namespace,
                    'description' => $serviceDescriptions[\strtolower($namespace)] ?? '',
                ];
                $seenServices[\strtolower($namespace)] = true;
            }

            foreach ($configuredServices as $serviceKey => $description) {
                if (isset($seenServices[\strtolower($serviceKey)])) {
                    continue;
                }

                $services[] = [
                    'name' => $serviceKey,
                    'description' => $description,
                ];
            }

            $models = $response->getModels();

            foreach ($models as $key => $value) {
                if ($platform !== null && $platform !== APP_SDK_PLATFORM_CONSOLE && !$value->isPublic()) {
                    unset($models[$key]);
                }
            }

            $arguments = [
                $specsContainer,
                $services,
                $routes,
                $models,
                $keys,
                $authCounts,
                $platform,
            ];

            foreach (['open-api3'] as $format) {
                $formatInstance = $this->getFormatInstance($format, $arguments);
                $specs = new Specification($formatInstance);

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

                $suffix = $platform === null ? '' : '-' . $platform;
                $path = $mocks
                    ? $specsDir . '/' . $format . '-mocks' . $suffix . '.json'
                    : $specsDir . '/' . $format . '-' . $version . $suffix . '.json';

                try {
                    $parsedSpecs = $specs->parse();
                } catch (\RuntimeException $e) {
                    // A throw is reported and carried on from, so stop here
                    Console::error('Spec generation failed for ' . ($platform ?? 'canonical') . " ({$format}): " . $e->getMessage());
                    Console::exit(1);
                    return;
                }

                $encodedSpecs = \json_encode($parsedSpecs, JSON_PRETTY_PRINT);

                unset($parsedSpecs);

                if ($encodedSpecs === false) {
                    throw new Exception('Failed to encode ' . ($mocks ? 'mocks ' : '') . 'spec file: ' . \json_last_error_msg());
                }

                if (\file_put_contents($path, $encodedSpecs) === false) {
                    throw new Exception('Failed to save ' . ($mocks ? 'mocks ' : '') . 'spec file: ' . $path);
                }

                $generatedFiles[realpath($path)] = $platform;
                Console::success('Saved ' . ($mocks ? 'mocks ' : '') . 'spec file: ' . realpath($path));

                unset($encodedSpecs, $specs, $formatInstance);
            }

            unset($arguments, $models, $routes, $services);
        }

        if ($git === 'yes') {
            $gitUrl = 'git@github.com:appwrite/specs.git';
            $gitRepoName = 'appwrite/specs';
            $gitBranch = 'feat-' . $version . '-specs';
            $repoBranch = $branch;
            $target = \realpath(__DIR__ . '/../../../../app') . '/sdks/git/specs/';
            $examplesDir = \realpath(__DIR__ . '/../../../..') . '/docs/examples/' . $version;

            Console::info("Cloning {$gitRepoName} into {$target}...");

            $stdout = '';
            $stderr = '';
            Console::execute(Command::and(
                (new Command('rm'))->flag('-rf')->argument($target),
                (new Command('mkdir'))->flag('-p')->argument($target),
                (new Command('cd'))->argument($target),
                (new Command('git'))->argument('init'),
                (new Command('git'))->argument('config')->argument('core.ignorecase')->argument('false'),
                (new Command('git'))->argument('config')->argument('pull.rebase')->argument('false'),
                (new Command('git'))->argument('remote')->argument('add')->argument('origin')->argument($gitUrl),
                (new Command('git'))->argument('fetch')->argument('origin'),
                Command::group(Command::or(
                    (new Command('git'))->argument('checkout')->flag('-f')->argument($repoBranch),
                    (new Command('git'))->argument('checkout')->flag('-b')->argument($repoBranch),
                )),
                (new Command('git'))->argument('pull')->argument('origin')->argument($repoBranch),
                Command::group(Command::or(
                    (new Command('git'))->argument('checkout')->flag('-f')->argument($gitBranch),
                    (new Command('git'))->argument('checkout')->flag('-b')->argument($gitBranch),
                )),
                Command::group(Command::or(
                    (new Command('git'))->argument('fetch')->argument('origin')->argument($gitBranch),
                    (new Command('git'))->argument('push')->flag('-u')->argument('origin')->argument($gitBranch),
                )),
                (new Command('git'))->argument('reset')->flag('--hard')->argument('origin/' . $gitBranch),
            ), '', $stdout, $stderr);

            // Copy the canonical document and the PR platforms' documents into specs/{version}/
            $prPlatforms = static::getPlatformsForPR();
            $prFiles = \array_keys(\array_filter(
                $generatedFiles,
                fn (?string $platform) => $platform === null || \in_array($platform, $prPlatforms, true)
            ));

            $specsSubDir = $mocks ? 'mocks' : $version;
            Console::execute((new Command('mkdir'))->flag('-p')->argument("{$target}/specs/{$specsSubDir}"), '', $stdout, $stderr);
            foreach ($prFiles as $file) {
                $fileName = \basename($file);
                Console::execute((new Command('cp'))->argument($file)->argument("{$target}/specs/{$specsSubDir}/{$fileName}"), '', $stdout, $stderr);
                Console::success("Copied spec file to repo: specs/{$specsSubDir}/{$fileName}");
            }

            // Regenerate SDK examples for this version
            Console::info("Regenerating SDK examples for version {$version}...");
            $sdksReturnCode = Console::execute($this->getSdksCommand($version), '', $stdout, $stderr);

            if ($sdksReturnCode !== 0) {
                Console::warning("SDK examples generation returned non-zero exit code: {$sdksReturnCode}");
                Console::warning(\trim($stdout . "\n" . $stderr));
            } else {
                Console::success("Regenerated SDK examples for version {$version}");
            }

            // Copy SDK examples for this version, filtered by PR platforms
            if (\is_dir($examplesDir)) {
                Console::execute((new Command('mkdir'))->flag('-p')->argument("{$target}/examples/{$version}"), '', $stdout, $stderr);
                $exampleFolders = \glob($examplesDir . '/*', GLOB_ONLYDIR) ?: [];
                foreach ($exampleFolders as $folder) {
                    $folderName = \basename($folder);
                    $platform = \strstr($folderName, '-', true) ?: $folderName;
                    if (!\in_array($platform, $prPlatforms, true)) {
                        Console::info("Skipping SDK examples for platform '{$platform}': examples/{$version}/{$folderName}");
                        continue;
                    }
                    Console::execute((new Command('cp'))->flag('-r')->argument($folder)->argument("{$target}/examples/{$version}/"), '', $stdout, $stderr);
                    Console::success("Copied SDK examples to repo: examples/{$version}/{$folderName}");
                }
            } else {
                Console::warning("No SDK examples found at: {$examplesDir}. Skipping examples copy.");
            }

            // Git add, commit, and push
            Console::execute(Command::and(
                (new Command('cd'))->argument($target),
                (new Command('git'))->argument('add')->flag('-A'),
                (new Command('git'))->argument('commit')->option('-m', $message),
                (new Command('git'))->argument('push')->flag('-u')->argument('origin')->argument($gitBranch),
            ), '', $stdout, $stderr);

            Console::success("Pushed specs to {$gitRepoName} on branch {$gitBranch}");

            // Create or update PR
            $prTitle = "feat: API specs update for version {$version}";
            $prBody = "This PR contains API specification updates and SDK examples for version {$version}.";

            Console::execute(Command::and(
                (new Command('cd'))->argument($target),
                (new Command('gh'))
                    ->argument('pr')
                    ->argument('create')
                    ->option('--repo', $gitRepoName)
                    ->option('--title', $prTitle)
                    ->option('--body', $prBody)
                    ->option('--base', $repoBranch)
                    ->option('--head', $gitBranch),
            ), '', $stdout, $stderr);
            $prOutput = \trim($stdout . "\n" . $stderr);

            if (\str_contains($prOutput, 'already exists')) {
                Console::warning("PR already exists for branch {$gitBranch}");
                // Try to get the existing PR URL
                Console::execute(Command::and(
                    (new Command('cd'))->argument($target),
                    (new Command('gh'))->argument('pr')->argument('view')->option('--repo', $gitRepoName)->option('--json', 'url')->option('-q', '.url'),
                ), '', $stdout, $stderr);
                $prUrl = \trim($stdout . "\n" . $stderr);
            } else {
                $prUrl = $prOutput;
            }

            // Clean up temp directory
            Console::execute(Command::and(
                (new Command('chmod'))->flag('-R')->argument('u+w')->argument($target),
                (new Command('rm'))->flag('-rf')->argument($target),
            ), '', $stdout, $stderr);
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
