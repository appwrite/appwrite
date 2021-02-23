<?php

use Appwrite\Database\Database;
use Appwrite\Specification\Format\OpenAPI3;
use Appwrite\Specification\Format\Swagger2;
use Appwrite\Specification\Specification;
use Utopia\App;
use Utopia\View;
use Utopia\Config\Config;
use Utopia\Exception;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

App::init(function ($layout) {
    /** @var Utopia\View $layout */

    $header = new View(__DIR__.'/../../views/home/comps/header.phtml');
    $footer = new View(__DIR__.'/../../views/home/comps/footer.phtml');

    $footer
        ->setParam('version', App::getEnv('_APP_VERSION', 'UNKNOWN'))
    ;

    $layout
        ->setParam('title', APP_NAME)
        ->setParam('description', '')
        ->setParam('class', 'home')
        ->setParam('platforms', Config::getParam('platforms'))
        ->setParam('header', [$header])
        ->setParam('footer', [$footer])
    ;
}, ['layout'], 'home');

App::shutdown(function ($response, $layout) {
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\View $layout */

    $response->html($layout->render());
}, ['response', 'layout'], 'home');

App::get('/')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->inject('project')
    ->inject('projectDB')
    ->action(function ($response, $projectDB, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Database\Document $project */

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Expires', 0)
            ->addHeader('Pragma', 'no-cache')
        ;

        if ('console' === $project->getId()) {
            $whitlistGod = $project->getAttribute('authWhitelistGod');

            if($whitlistGod !== 'disabled') {
                $sum = $projectDB->getCount([ // Count users
                    'limit' => 1,
                    'filters' => [
                        '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    ],
                ]);

                if($sum !== 0) {
                    return $response->redirect('/auth/signin');
                }
            }
        }

        $response->redirect('/auth/signup');
    });

App::get('/auth/signin')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function ($layout) {
        /** @var Utopia\View $layout */

        $page = new View(__DIR__.'/../../views/home/auth/signin.phtml');

        $page
            ->setParam('god', App::getEnv('_APP_CONSOLE_WHITELIST_GOD', 'enabled'))
        ;

        $layout
            ->setParam('title', 'Sign In - '.APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/signup')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function ($layout) {
        /** @var Utopia\View $layout */
        $page = new View(__DIR__.'/../../views/home/auth/signup.phtml');

        $page
            ->setParam('god', App::getEnv('_APP_CONSOLE_WHITELIST_GOD', 'enabled'))
        ;

        $layout
            ->setParam('title', 'Sign Up - '.APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/recovery')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function ($layout) {
        /** @var Utopia\View $layout */

        $page = new View(__DIR__.'/../../views/home/auth/recovery.phtml');

        $layout
            ->setParam('title', 'Password Recovery - '.APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/confirm')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function ($layout) {
        /** @var Utopia\View $layout */

        $page = new View(__DIR__.'/../../views/home/auth/confirm.phtml');

        $layout
            ->setParam('title', 'Account Confirmation - '.APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/join')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function ($layout) {
        /** @var Utopia\View $layout */

        $page = new View(__DIR__.'/../../views/home/auth/join.phtml');

        $layout
            ->setParam('title', 'Invitation - '.APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/recovery/reset')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function ($layout) {
        /** @var Utopia\View $layout */

        $page = new View(__DIR__.'/../../views/home/auth/recovery/reset.phtml');

        $layout
            ->setParam('title', 'Password Reset - '.APP_NAME)
            ->setParam('body', $page);
    });

App::get('/auth/oauth2/success')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function ($layout) {
        /** @var Utopia\View $layout */

        $page = new View(__DIR__.'/../../views/home/auth/oauth2.phtml');

        $layout
            ->setParam('title', APP_NAME)
            ->setParam('body', $page)
            ->setParam('header', [])
            ->setParam('footer', [])
        ;
    });

App::get('/auth/oauth2/failure')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('layout')
    ->action(function ($layout) {
        /** @var Utopia\View $layout */

        $page = new View(__DIR__.'/../../views/home/auth/oauth2.phtml');

        $layout
            ->setParam('title', APP_NAME)
            ->setParam('body', $page)
            ->setParam('header', [])
            ->setParam('footer', [])
        ;
    });

App::get('/error/:code')
    ->groups(['web', 'home'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->param('code', null, new \Utopia\Validator\Numeric(), 'Valid status code number', false)
    ->inject('layout')
    ->action(function ($code, $layout) {
        /** @var Utopia\View $layout */

        $page = new View(__DIR__.'/../../views/error.phtml');

        $page
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', 'Error'.' - '.APP_NAME)
            ->setParam('body', $page);
    });

App::get('/specs/:format')
    ->groups(['web', 'home'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('format', 'swagger2', new WhiteList(['swagger2', 'open-api3'], true), 'Spec format.', true)
    ->param('platform', APP_PLATFORM_CLIENT, new WhiteList([APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER, APP_PLATFORM_CONSOLE], true), 'Choose target platform.', true)
    ->param('tests', 0, function () {return new Range(0, 1);}, 'Include only test services.', true)
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->action(function ($format, $platform, $tests, $utopia, $request, $response) {
        /** @var Utopia\App $utopia */
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */

        $platforms = [
            'client' => APP_PLATFORM_CLIENT,
            'server' => APP_PLATFORM_SERVER,
            'console' => APP_PLATFORM_CONSOLE,
        ];

        $routes = [];
        $models = [];
        $services = [];

        $keys = [
            APP_PLATFORM_CLIENT => [
                'Project' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Project',
                    'description' => 'Your project ID',
                    'in' => 'header',
                ],
                'Locale' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Locale',
                    'description' => '',
                    'in' => 'header',
                ],
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
                'Locale' => [
                    'type' => 'apiKey',
                    'name' => 'X-Appwrite-Locale',
                    'description' => '',
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

        $security = [
            APP_PLATFORM_CLIENT => ['Project' => []],
            APP_PLATFORM_SERVER => ['Project' => [], 'Key' => []],
            APP_PLATFORM_CONSOLE => ['Project' => [], 'Key' => []],
        ];

        foreach ($utopia->getRoutes() as $key => $method) {
            foreach ($method as $route) { /** @var \Utopia\Route $route */
                if (!$route->getLabel('docs', true)) {
                    continue;
                }

                if ($route->getLabel('sdk.mock', false) && !$tests) {
                    continue;
                }

                if (!$route->getLabel('sdk.mock', false) && $tests) {
                    continue;
                }

                if (empty($route->getLabel('sdk.namespace', null))) {
                    continue;
                }

                if ($platform !== APP_PLATFORM_CONSOLE && !\in_array($platforms[$platform], $route->getLabel('sdk.platform', []))) {
                    continue;
                }

                $routes[] = $route;
                $model = $response->getModel($route->getLabel('sdk.response.model', 'none'));
                
                if($model) {
                    $models[$model->getType()] = $model;
                }
            }
        }

        foreach (Config::getParam('services', []) as $key => $service) {
            if(!isset($service['docs']) // Skip service if not part of the public API
                || !isset($service['sdk'])
                || !$service['docs']
                || !$service['sdk']) {
                continue;
            }

            $services[] = [
                'name' => $service['key'] ?? '',
                'description' => $service['subtitle'] ?? '',
            ];
        }

        $models = $response->getModels();

        foreach ($models as $key => $value) {
            if($platform !== APP_PLATFORM_CONSOLE && !$value->isPublic()) {
                unset($models[$key]);
            }
        }

        switch ($format) {
            case 'swagger2':
                $format = new Swagger2($utopia, $services, $routes, $models, $keys[$platform], $security[$platform]);
                break;

            case 'open-api3':
                $format = new OpenAPI3($utopia, $services, $routes, $models, $keys[$platform], $security[$platform]);
                break;
            
            default:
                throw new Exception('Format not found', 404);
                break;
        }

        $specs = new Specification($format);
        
        $format
            ->setParam('name', APP_NAME)
            ->setParam('description', 'Appwrite backend as a service cuts up to 70% of the time and costs required for building a modern application. We abstract and simplify common development tasks behind a REST APIs, to help you develop your app in a fast and secure way. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)')
            ->setParam('endpoint', App::getEnv('_APP_HOME', $request->getProtocol().'://'.$request->getHostname()).'/v1')
            ->setParam('version', APP_VERSION_STABLE)
            ->setParam('terms', App::getEnv('_APP_HOME', $request->getProtocol().'://'.$request->getHostname()).'/policy/terms')
            ->setParam('support.email', App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM))
            ->setParam('support.url', App::getEnv('_APP_HOME', $request->getProtocol().'://'.$request->getHostname()).'/support')
            ->setParam('contact.name', APP_NAME.' Team')
            ->setParam('contact.email', App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM))
            ->setParam('contact.url', App::getEnv('_APP_HOME', $request->getProtocol().'://'.$request->getHostname()).'/support')
            ->setParam('license.name', 'BSD-3-Clause')
            ->setParam('license.url', 'https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE')
            ->setParam('docs.description', 'Full API docs, specs and tutorials')
            ->setParam('docs.url', App::getEnv('_APP_HOME', $request->getProtocol().'://'.$request->getHostname()).'/docs')
        ;

        $response
            ->json($specs->parse());
    });