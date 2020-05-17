<?php

include_once __DIR__ . '/../shared/web.php';

global $utopia, $response, $request, $layout;

use Utopia\View;
use Utopia\Config\Config;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Range;

$header = new View(__DIR__.'/../../views/home/comps/header.phtml');
$footer = new View(__DIR__.'/../../views/home/comps/footer.phtml');

$footer
    ->setParam('version', Config::getParam('version'))
;

$layout
    ->setParam('title', APP_NAME)
    ->setParam('description', '')
    ->setParam('class', 'home')
    ->setParam('platforms', Config::getParam('platforms'))
    ->setParam('header', [$header])
    ->setParam('footer', [$footer])
;

$utopia->shutdown(function () use ($response, $layout) {
    $response->send($layout->render());
});

$utopia->get('/')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(
        function () use ($response) {
            $response->redirect('/auth/signin');
        }
    );

$utopia->get('/auth/signin')
    ->desc('Login page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/home/auth/signin.phtml');

        $layout
            ->setParam('title', 'Sign In - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/signup')
    ->desc('Registration page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/home/auth/signup.phtml');

        $layout
            ->setParam('title', 'Sign Up - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/recovery')
    ->desc('Password recovery page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($request, $layout) {
        $page = new View(__DIR__.'/../../views/home/auth/recovery.phtml');

        $layout
            ->setParam('title', 'Password Recovery - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/confirm')
    ->desc('Account confirmation page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/home/auth/confirm.phtml');

        $layout
            ->setParam('title', 'Account Confirmation - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/join')
    ->desc('Account team join page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/home/auth/join.phtml');

        $layout
            ->setParam('title', 'Invitation - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/recovery/reset')
    ->desc('Password recovery page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/home/auth/recovery/reset.phtml');

        $layout
            ->setParam('title', 'Password Reset - '.APP_NAME)
            ->setParam('body', $page);
    });


$utopia->get('/auth/oauth2/success')
    ->desc('Registration page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/home/auth/oauth2.phtml');

        $layout
            ->setParam('title', APP_NAME)
            ->setParam('body', $page)
            ->setParam('header', [])
            ->setParam('footer', [])
        ;
    });

$utopia->get('/auth/oauth2/failure')
    ->desc('Registration page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../../views/home/auth/oauth2.phtml');

        $layout
            ->setParam('title', APP_NAME)
            ->setParam('body', $page)
            ->setParam('header', [])
            ->setParam('footer', [])
        ;
    });

$utopia->get('/error/:code')
    ->desc('Error page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->param('code', null, new \Utopia\Validator\Numeric(), 'Valid status code number', false)
    ->action(function ($code) use ($layout) {
        $page = new View(__DIR__.'/../../views/error.phtml');

        $page
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', 'Error'.' - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/open-api-2.json')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('platform', APP_PLATFORM_CLIENT, function () {return new WhiteList([APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER, APP_PLATFORM_CONSOLE]);}, 'Choose target platform.', true)
    ->param('extensions', 0, function () {return new Range(0, 1);}, 'Show extra data.', true)
    ->param('tests', 0, function () {return new Range(0, 1);}, 'Include only test services.', true)
    ->action(
        function ($platform, $extensions, $tests) use ($response, $request, $utopia, $services) {
            function fromCamelCase($input)
            {
                preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
                $ret = $matches[0];
                foreach ($ret as &$match) {
                    $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
                }

                return implode('_', $ret);
            }

            function fromCamelCaseToDash($input)
            {
                return str_replace([' ', '_'], '-', strtolower(preg_replace('/([a-zA-Z])(?=[A-Z])/', '$1-', $input)));
            }

            foreach ($services as $service) { /* @noinspection PhpIncludeInspection */
                if($tests && !isset($service['tests'])) {
                    continue;
                }

                if($tests && !$service['tests']) {
                    continue;
                }
                
                if (!$tests && !$service['sdk']) {
                    continue;
                }
             
                /** @noinspection PhpIncludeInspection */
                include_once realpath(__DIR__.'/../../'.$service['controller']);
            }

            $security = [
                APP_PLATFORM_CLIENT => ['Project' => []],
                APP_PLATFORM_SERVER => ['Project' => [], 'Key' => []],
                APP_PLATFORM_CONSOLE => ['Project' => [], 'Key' => []],
            ];

            $platforms = [
                'client' => APP_PLATFORM_CLIENT,
                'server' => APP_PLATFORM_SERVER,
                'all' => APP_PLATFORM_CONSOLE,
            ];

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

            /*
            * Specifications (v3.0.0):
            * https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.0.md
            */
            $output = [
                'swagger' => '2.0',
                'info' => [
                    'version' => APP_VERSION_STABLE,
                    'title' => APP_NAME,
                    'description' => 'Appwrite backend as a service cuts up to 70% of the time and costs required for building a modern application. We abstract and simplify common development tasks behind a REST APIs, to help you develop your app in a fast and secure way. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)',
                    'termsOfService' => 'https://appwrite.io/policy/terms',
                    'contact' => [
                        'name' => 'Appwrite Team',
                        'url' => 'https://appwrite.io/support',
                        'email' => $request->getServer('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM),
                    ],
                    'license' => [
                        'name' => 'BSD-3-Clause',
                        'url' => 'https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE',
                    ],
                ],
                'host' => parse_url($request->getServer('_APP_HOME', Config::getParam('domain')), PHP_URL_HOST),
                'basePath' => '/v1',
                'schemes' => ['https'],
                'consumes' => ['application/json', 'multipart/form-data'],
                'produces' => ['application/json'],
                'securityDefinitions' => $keys[$platform],
                'paths' => [],
                'definitions' => [
                    // 'Pet' => [
                    //     'required' => ['id', 'name'],
                    //     'properties' => [
                    //         'id' => [
                    //             'type' => 'integer',
                    //             'format' => 'int64',
                    //         ],
                    //         'name' => [
                    //             'type' => 'string',
                    //         ],
                    //         'tag' => [
                    //             'type' => 'string',
                    //         ],
                    //     ],
                    // ],
                    // 'Pets' => array(
                    //         'type' => 'array',
                    //         'items' => array(
                    //                 '$ref' => '#/definitions/Pet',
                    //             ),
                    //     ),
                    'Error' => array(
                            'required' => array(
                                    0 => 'code',
                                    1 => 'message',
                                ),
                            'properties' => array(
                                    'code' => array(
                                            'type' => 'integer',
                                            'format' => 'int32',
                                        ),
                                    'message' => array(
                                            'type' => 'string',
                                        ),
                                ),
                        ),
                ],
                'externalDocs' => [
                    'description' => 'Full API docs, specs and tutorials',
                    'url' => Config::getParam('protocol').'://'.Config::getParam('domain').'/docs',
                ],
            ];

            if ($extensions) {
                if(isset($output['securityDefinitions']['Project'])) {
                    $output['securityDefinitions']['Project']['extensions'] = ['demo' => '5df5acd0d48c2'];
                }
                
                if(isset($output['securityDefinitions']['Key'])) {
                    $output['securityDefinitions']['Key']['extensions'] = ['demo' => '919c2d18fb5d4...a2ae413da83346ad2'];
                }
                
                if(isset($output['securityDefinitions']['Locale'])) {
                    $output['securityDefinitions']['Locale']['extensions'] = ['demo' => 'en'];
                }

                if(isset($output['securityDefinitions']['Mode'])) {
                    $output['securityDefinitions']['Mode']['extensions'] = ['demo' => ''];
                }
            }

            foreach ($utopia->getRoutes() as $key => $method) {
                foreach ($method as $route) { /* @var $route \Utopia\Route */
                    if (!$route->getLabel('docs', true)) {
                        continue;
                    }

                    if (empty($route->getLabel('sdk.namespace', null))) {
                        continue;
                    }

                    if($platform !== APP_PLATFORM_CONSOLE && !in_array($platforms[$platform], $route->getLabel('sdk.platform', []))) {
                        continue;
                    }

                    $url = str_replace('/v1', '', $route->getURL());
                    $scope = $route->getLabel('scope', '');
                    $hide = $route->getLabel('sdk.hide', false);
                    $consumes = ['application/json'];

                    if ($hide) {
                        continue;
                    }

                    $desc = (!empty($route->getLabel('sdk.description', ''))) ? realpath('../'.$route->getLabel('sdk.description', '')) : null;
        
                    $temp = [
                        'summary' => $route->getDesc(),
                        'operationId' => $route->getLabel('sdk.method', uniqid()),
                        'consumes' => [],
                        'tags' => [$route->getLabel('sdk.namespace', 'default')],
                        'description' => ($desc) ? file_get_contents($desc) : '',
                        
                        // 'responses' => [
                        //     200 => [
                        //         'description' => 'An paged array of pets',
                        //         'schema' => [
                        //             '$ref' => '#/definitions/Pet',
                        //         ],
                        //     ],
                        // ],
                    ];

                    if ($extensions) {
                        $platformList = $route->getLabel('sdk.platform', []);

                        $temp['extensions'] = [
                            'weight' => $route->getOrder(),
                            'cookies' => $route->getLabel('sdk.cookies', false),
                            'type' => $route->getLabel('sdk.methodType', ''),
                            'demo' => 'docs/examples/'.fromCamelCaseToDash($route->getLabel('sdk.namespace', 'default')).'/'.fromCamelCaseToDash($temp['operationId']).'.md',
                            'edit' => 'https://github.com/appwrite/appwrite/edit/master' . $route->getLabel('sdk.description', ''),
                            'rate-limit' => $route->getLabel('abuse-limit', 0),
                            'rate-time' => $route->getLabel('abuse-time', 3600),
                            'scope' => $route->getLabel('scope', ''),
                            'platforms' => $platformList,
                        ];
                    }

                    if ((!empty($scope))) { //  && 'public' != $scope
                        $temp['security'][] = $route->getLabel('sdk.security', $security[$platform]);
                    }

                    $requestBody = [
                        'content' => [
                            'application/x-www-form-urlencoded' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [],
                                ],
                                'required' => [],
                            ],
                        ],
                    ];

                    foreach ($route->getParams() as $name => $param) {
                        $validator = (is_callable($param['validator'])) ? $param['validator']() : $param['validator']; /* @var $validator \Utopia\Validator */

                        $node = [
                            'name' => $name,
                            'description' => $param['description'],
                            'required' => !$param['optional'],
                        ];

                        switch ((!empty($validator)) ? get_class($validator) : '') {
                            case 'Utopia\Validator\Text':
                                $node['type'] = 'string';
                                $node['x-example'] = '['.strtoupper(fromCamelCase($node['name'])).']';
                                break;
                            case 'Appwrite\Database\Validator\UID':
                                $node['type'] = 'string';
                                $node['x-example'] = '['.strtoupper(fromCamelCase($node['name'])).']';
                                break;
                            case 'Utopia\Validator\Email':
                                $node['type'] = 'string';
                                $node['format'] = 'email';
                                $node['x-example'] = 'email@example.com';
                                break;
                            case 'Utopia\Validator\URL':
                                $node['type'] = 'string';
                                $node['format'] = 'url';
                                $node['x-example'] = 'https://example.com';
                                break;
                            case 'Utopia\Validator\JSON':
                            case 'Utopia\Validator\Mock':
                            case 'Utopia\Validator\Assoc':
                                $node['type'] = 'object';
                                $node['type'] = 'object';
                                $node['x-example'] = '{}';
                                //$node['format'] = 'json';
                                break;
                            case 'Appwrite\Storage\Validators\File':
                                $consumes = ['multipart/form-data'];
                                $node['type'] = 'file';
                                break;
                            case 'Utopia\Validator\ArrayList':
                                $node['type'] = 'array';
                                $node['collectionFormat'] = 'multi';
                                $node['items'] = [
                                    'type' => 'string',
                                ];
                                break;
                            case 'Appwrite\Auth\Validator\Password':
                                $node['type'] = 'string';
                                $node['format'] = 'format';
                                $node['x-example'] = 'password';
                                break;
                            case 'Utopia\Validator\Range': /* @var $validator \Utopia\Validator\Range */
                                $node['type'] = 'integer';
                                $node['format'] = 'int32';
                                $node['x-example'] = $validator->getMin();
                                break;
                            case 'Utopia\Validator\Numeric':
                                $node['type'] = 'integer';
                                $node['format'] = 'int32';
                                break;
                            case 'Utopia\Validator\Length':
                                $node['type'] = 'string';
                                break;
                            case 'Utopia\Validator\Host':
                                $node['type'] = 'string';
                                $node['format'] = 'url';
                                $node['x-example'] = 'https://example.com';
                                break;
                            case 'Utopia\Validator\WhiteList': /* @var $validator \Utopia\Validator\WhiteList */
                                $node['type'] = 'string';
                                $node['x-example'] = $validator->getList()[0];
                                break;
                            default:
                                $node['type'] = 'string';
                                break;
                        }

                        if ($param['optional'] && !is_null($param['default'])) { // Param has default value
                            $node['default'] = $param['default'];
                        }

                        if (false !== strpos($url, ':'.$name)) { // Param is in URL path
                            $node['in'] = 'path';
                            $temp['parameters'][] = $node;
                        } elseif ($key == 'GET') { // Param is in query
                            $node['in'] = 'query';
                            $temp['parameters'][] = $node;
                        } else { // Param is in payload
                            $node['in'] = 'formData';
                            $temp['parameters'][] = $node;
                            $requestBody['content']['application/x-www-form-urlencoded']['schema']['properties'][] = $node;

                            if (!$param['optional']) {
                                $requestBody['content']['application/x-www-form-urlencoded']['required'][] = $name;
                            }
                        }

                        $url = str_replace(':'.$name, '{'.$name.'}', $url);
                    }

                    $temp['consumes'] = $consumes;

                    $output['paths'][$url][strtolower($route->getMethod())] = $temp;
                }
            }

            /*foreach ($consoleDB->getMocks() as $mock) {
                var_dump($mock['name']);
            }*/

            ksort($output['paths']);

            $response
                ->json($output);
        }
    );