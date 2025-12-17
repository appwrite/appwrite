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
use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Request as UtopiaRequest;
use Utopia\Response as UtopiaResponse;
use Utopia\System\System;
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
    protected function getPlatforms(): array
    {
        return [
            APP_SDK_PLATFORM_CLIENT,
            APP_SDK_PLATFORM_SERVER,
            APP_SDK_PLATFORM_CONSOLE,
        ];
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

    public function action(string $version, string $mode): void
    {
        $appRoutes = App::getRoutes();

        /** @var AppwriteResponse $response */
        $response = $this->getResponse();

        $mocks = ($mode === 'mocks');

        // Mock dependencies
        App::setResource('request', fn () => $this->getRequest());
        App::setResource('response', fn () => $response);
        App::setResource('dbForPlatform', fn () => new Database(new MySQL(''), new Cache(new None())));
        App::setResource('dbForProject', fn () => new Database(new MySQL(''), new Cache(new None())));

        $platforms = $this->getPlatforms();
        $authCounts = $this->getAuthCounts();
        $keys = $this->getKeys();

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
                new App('UTC'),
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

                    Console::success('Saved mocks spec file: ' . realpath($path));

                    continue;
                }

                $path = __DIR__ . '/../../../../app/config/specs/' . $format . '-' . $version . '-' . $platform . '.json';

                if (!file_put_contents($path, json_encode($specs->parse(), JSON_PRETTY_PRINT))) {
                    throw new Exception('Failed to save spec file: ' . $path);
                }

                Console::success('Saved spec file: ' . realpath($path));
            }
        }
    }
}
