<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\SDK\AuthType;
use Appwrite\Specification\Format\OpenAPI3;
use Appwrite\Specification\Format\Swagger2;
use Appwrite\Specification\Specification;
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
use Utopia\Registry\Registry;
use Utopia\Request as UtopiaRequest;
use Utopia\Response as UtopiaResponse;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Specs extends Action
{
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

    public function __construct()
    {
        $this
            ->desc('Generate Appwrite API specifications')
            ->param('version', 'latest', new Text(16), 'Spec version', true)
            ->param('mode', 'normal', new WhiteList(['normal', 'mocks']), 'Spec Mode', true)
            ->inject('register')
            ->callback(fn (string $version, string $mode, Registry $register) => $this->action($version, $mode, $register));
    }

    public function action(string $version, string $mode, Registry $register): void
    {
        $appRoutes = App::getRoutes();
        $response = $this->getResponse();
        $mocks = ($mode === 'mocks');

        // Mock dependencies
        App::setResource('request', fn () => $this->getRequest());
        App::setResource('response', fn () => $response);
        App::setResource('dbForPlatform', fn () => new Database(new MySQL(''), new Cache(new None())));
        App::setResource('dbForProject', fn () => new Database(new MySQL(''), new Cache(new None())));

        $platforms = [
            'client' => APP_PLATFORM_CLIENT,
            'server' => APP_PLATFORM_SERVER,
            'console' => APP_PLATFORM_CONSOLE,
        ];

        $authCounts = [
            'client' => 1,
            'server' => 2,
            'console' => 1,
        ];

        $keys = [
            APP_PLATFORM_CLIENT => [
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
                ]
            ],
            APP_PLATFORM_SERVER => [
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
            APP_PLATFORM_CONSOLE => [
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
                        /** @var \Appwrite\SDK\Method $sdks */

                        $hide = $sdk->isHidden();
                        if ($hide === true || (\is_array($hide) && \in_array($platform, $hide))) {
                            continue;
                        }

                        $routeSecurity = $sdk->getAuth();
                        $sdkPlatforms = [];

                        foreach ($routeSecurity as $value) {
                            switch ($value) {
                                case AuthType::SESSION:
                                    $sdkPlatforms[] = APP_PLATFORM_CLIENT;
                                    break;
                                case AuthType::JWT:
                                case AuthType::KEY:
                                    $sdkPlatforms[] = APP_PLATFORM_SERVER;
                                    break;
                                case AuthType::ADMIN:
                                    $sdkPlatforms[] = APP_PLATFORM_CONSOLE;
                                    break;
                            }
                        }

                        if (empty($routeSecurity)) {
                            $sdkPlatforms[] = APP_PLATFORM_SERVER;
                            $sdkPlatforms[] = APP_PLATFORM_CLIENT;
                        }

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

                        if ($platform !== APP_PLATFORM_CONSOLE && !\in_array($platforms[$platform], $sdkPlatforms)) {
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

                $services[] = [
                    'name' => $service['key'] ?? '',
                    'description' => $service['subtitle'] ?? '',
                    'x-globalAttributes' => $service['globalAttributes'] ?? [],
                ];
            }

            $models = $response->getModels();

            foreach ($models as $key => $value) {
                if ($platform !== APP_PLATFORM_CONSOLE && !$value->isPublic()) {
                    unset($models[$key]);
                }
            }

            $arguments = [new App('UTC'), $services, $routes, $models, $keys[$platform], $authCounts[$platform] ?? 0];
            foreach (['swagger2', 'open-api3'] as $format) {
                $formatInstance = match ($format) {
                    'swagger2' => new Swagger2(...$arguments),
                    'open-api3' => new OpenAPI3(...$arguments),
                    default => throw new Exception('Format not found: ' . $format)
                };

                $specs = new Specification($formatInstance);
                $endpoint = System::getEnv('_APP_HOME', '[HOSTNAME]');
                $email = System::getEnv('_APP_SYSTEM_TEAM_EMAIL', APP_EMAIL_TEAM);

                $formatInstance
                    ->setParam('name', APP_NAME)
                    ->setParam('description', 'Appwrite backend as a service cuts up to 70% of the time and costs required for building a modern application. We abstract and simplify common development tasks behind a REST APIs, to help you develop your app in a fast and secure way. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)')
                    ->setParam('endpoint', 'https://cloud.appwrite.io/v1')
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