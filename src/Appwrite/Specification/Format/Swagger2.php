<?php

namespace Appwrite\Specification\Format;

use Appwrite\Specification\Format;
use Appwrite\Template\Template;
use Utopia\Validator;

class Swagger2 extends Format
{
    /**
     * Get Name.
     *
     * Get format name
     *
     * @return string
     */
    public function getName():string
    {
        return 'Swagger 2';
    }

    /**
     * Get Used Models
     *
     * Recursively get all used models
     *
     * @param object $model
     * @param array $models
     *
     * @return void
     */
    protected function getUsedModels($model, array &$usedModels)
    {
        if (is_string($model) && !in_array($model, ['string', 'integer', 'boolean', 'json', 'float', 'double'])) {
            $usedModels[] = $model;
            return;
        }
        if (!is_object($model)) {
            return;
        }
        foreach ($model->getRules() as $rule) {
            if (\is_array($rule['type'])) {
                foreach ($rule['type'] as $type) {
                    $this->getUsedModels($type, $usedModels);
                }
            } else {
                $this->getUsedModels($rule['type'], $usedModels);
            }
        }
    }

    /**
     * Parse
     *
     * Parses Appwrite App to given format
     *
     * @return array
     */
    public function parse(): array
    {
        /*
        * Specifications (v3.0.0):
        * https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.0.md
        */
        $output = [
            'swagger' => '2.0',
            'info' => [
                'version' => $this->getParam('version'),
                'title' => $this->getParam('name'),
                'description' => $this->getParam('description'),
                'termsOfService' => $this->getParam('terms'),
                'contact' => [
                    'name' => $this->getParam('contact.name'),
                    'url' => $this->getParam('contact.url'),
                    'email' => $this->getParam('contact.email'),
                ],
                'license' => [
                    'name' => 'BSD-3-Clause',
                    'url' => 'https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE',
                ],
            ],
            'host' => \parse_url($this->getParam('endpoint', ''), PHP_URL_HOST),
            'basePath' => \parse_url($this->getParam('endpoint', ''), PHP_URL_PATH),
            'schemes' => [\parse_url($this->getParam('endpoint', ''), PHP_URL_SCHEME)],
            'consumes' => ['application/json', 'multipart/form-data'],
            'produces' => ['application/json'],
            'securityDefinitions' => $this->keys,
            'paths' => [],
            'tags' => $this->services,
            'definitions' => [],
            'externalDocs' => [
                'description' => $this->getParam('docs.description'),
                'url' => $this->getParam('docs.url'),
            ],
        ];

        if (isset($output['securityDefinitions']['Project'])) {
            $output['securityDefinitions']['Project']['x-appwrite'] = ['demo' => '5df5acd0d48c2'];
        }

        if (isset($output['securityDefinitions']['Key'])) {
            $output['securityDefinitions']['Key']['x-appwrite'] = ['demo' => '919c2d18fb5d4...a2ae413da83346ad2'];
        }

        if (isset($output['securityDefinitions']['JWT'])) {
            $output['securityDefinitions']['JWT']['x-appwrite'] = ['demo' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...'];
        }

        if (isset($output['securityDefinitions']['Locale'])) {
            $output['securityDefinitions']['Locale']['x-appwrite'] = ['demo' => 'en'];
        }

        if (isset($output['securityDefinitions']['Mode'])) {
            $output['securityDefinitions']['Mode']['x-appwrite'] = ['demo' => ''];
        }

        $usedModels = [];

        foreach ($this->routes as $route) { /** @var \Utopia\Route $route */
            $url = \str_replace('/v1', '', $route->getPath());
            $scope = $route->getLabel('scope', '');
            $hide = $route->getLabel('sdk.hide', false);
            $consumes = [$route->getLabel('sdk.request.type', 'application/json')];

            if ($hide) {
                continue;
            }

            $id = $route->getLabel('sdk.method', \uniqid());
            $desc = (!empty($route->getLabel('sdk.description', ''))) ? \realpath(__DIR__.'/../../../../'.$route->getLabel('sdk.description', '')) : null;
            $produces = $route->getLabel('sdk.response.type', null);
            $model = $route->getLabel('sdk.response.model', 'none');
            $routeSecurity = $route->getLabel('sdk.auth', []);
            $sdkPlatofrms = [];

            foreach ($routeSecurity as $value) {
                switch ($value) {
                    case APP_AUTH_TYPE_SESSION:
                        $sdkPlatofrms[] = APP_PLATFORM_CLIENT;
                        break;
                    case APP_AUTH_TYPE_KEY:
                        $sdkPlatofrms[] = APP_PLATFORM_SERVER;
                        break;
                    case APP_AUTH_TYPE_JWT:
                        $sdkPlatofrms[] = APP_PLATFORM_SERVER;
                        break;
                    case APP_AUTH_TYPE_ADMIN:
                        $sdkPlatofrms[] = APP_PLATFORM_CONSOLE;
                        break;
                }
            }

            if (empty($routeSecurity)) {
                $sdkPlatofrms[] = APP_PLATFORM_CLIENT;
            }

            $temp = [
                'summary' => $route->getDesc(),
                'operationId' => $route->getLabel('sdk.namespace', 'default').ucfirst($id),
                'consumes' => [],
                'produces' => [],
                'tags' => [$route->getLabel('sdk.namespace', 'default')],
                'description' => ($desc) ? \file_get_contents($desc) : '',
                'responses' => [],
                'x-appwrite' => [ // Appwrite related metadata
                    'method' => $route->getLabel('sdk.method', \uniqid()),
                    'weight' => $route->getOrder(),
                    'cookies' => $route->getLabel('sdk.cookies', false),
                    'type' => $route->getLabel('sdk.methodType', ''),
                    'demo' => Template::fromCamelCaseToDash($route->getLabel('sdk.namespace', 'default')).'/'.Template::fromCamelCaseToDash($id).'.md',
                    'edit' => 'https://github.com/appwrite/appwrite/edit/master' . $route->getLabel('sdk.description', ''),
                    'rate-limit' => $route->getLabel('abuse-limit', 0),
                    'rate-time' => $route->getLabel('abuse-time', 3600),
                    'rate-key' => $route->getLabel('abuse-key', 'url:{url},ip:{ip}'),
                    'scope' => $route->getLabel('scope', ''),
                    'platforms' => $sdkPlatofrms,
                    'packaging' => $route->getLabel('sdk.packaging', false),
                ],
            ];

            if ($produces) {
                $temp['produces'][] = $produces;
            }

            foreach ($this->models as $key => $value) {
                if (\is_array($model)) {
                    $model = \array_map(function ($m) use ($value) {
                        if ($m === $value->getType()) {
                            return $value;
                        }
                        return $m;
                    }, $model);
                } else {
                    if ($value->getType() === $model) {
                        $model = $value;
                        break;
                    }
                }
            }

            if (!(\is_array($model)) &&  $model->isNone()) {
                $temp['responses'][(string)$route->getLabel('sdk.response.code', '500')] = [
                    'description' => (in_array($produces, [
                        'image/*',
                        'image/jpeg',
                        'image/gif',
                        'image/png',
                        'image/webp',
                        'image/svg-x',
                        'image/x-icon',
                        'image/bmp',
                    ])) ? 'Image' : 'File',
                    'schema' => [
                        'type' => 'file'
                    ],
                ];
            } else {
                if (\is_array($model)) {
                    $modelDescription = \join(', or ', \array_map(function ($m) {
                        return $m->getName();
                    }, $model));
                    // model has multiple possible responses, we will use oneOf
                    foreach ($model as $m) {
                        $usedModels[] = $m->getType();
                    }
                    $temp['responses'][(string)$route->getLabel('sdk.response.code', '500')] = [
                        'description' => $modelDescription,
                        'content' => [
                            $produces => [
                                'schema' => [
                                    'oneOf' => \array_map(function ($m) {
                                        return ['$ref' => '#/definitions/'.$m->getType()];
                                    }, $model)
                                ],
                            ],
                        ],
                    ];
                } else {
                    // Response definition using one type
                    $usedModels[] = $model->getType();
                    $temp['responses'][(string)$route->getLabel('sdk.response.code', '500')] = [
                        'description' => $model->getName(),
                        'content' => [
                            $produces => [
                                'schema' => [
                                    '$ref' => '#/definitions/'.$model->getType(),
                                ],
                            ],
                        ],
                    ];
                }
            }

            if (in_array($route->getLabel('sdk.response.code', 500), [204, 301, 302, 308], true)) {
                $temp['responses'][(string)$route->getLabel('sdk.response.code', '500')]['description'] = 'No content';
                unset($temp['responses'][(string)$route->getLabel('sdk.response.code', '500')]['schema']);
            }

            if ((!empty($scope))) { //  && 'public' != $scope
                $securities = ['Project' => []];

                foreach ($route->getLabel('sdk.auth', []) as $security) {
                    if (array_key_exists($security, $this->keys)) {
                        $securities[$security] = [];
                    }
                }

                $temp['x-appwrite']['auth'] = array_slice($securities, 0, $this->authCount);
                $temp['security'][] = $securities;
            }

            $body = [
                'name' => 'payload',
                'in' => 'body',
                'schema' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ];

            $bodyRequired = [];

            foreach ($route->getParams() as $name => $param) { // Set params
                $validator = (\is_callable($param['validator'])) ? call_user_func_array($param['validator'], $this->app->getResources($param['injections'])) : $param['validator']; /** @var \Utopia\Validator $validator */

                $node = [
                    'name' => $name,
                    'description' => $param['description'],
                    'required' => !$param['optional'],
                ];

                switch ((!empty($validator)) ? \get_class($validator) : '') {
                    case 'Utopia\Validator\Text':
                        $node['type'] = $validator->getType();
                        $node['x-example'] = '['.\strtoupper(Template::fromCamelCaseToSnake($node['name'])).']';
                        break;
                    case 'Utopia\Validator\Boolean':
                        $node['type'] = $validator->getType();
                        $node['x-example'] = false;
                        break;
                    case 'Utopia\Database\Validator\UID':
                        $node['type'] = $validator->getType();
                        $node['x-example'] = '['.\strtoupper(Template::fromCamelCaseToSnake($node['name'])).']';
                        break;
                    case 'Appwrite\Network\Validator\Email':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'email';
                        $node['x-example'] = 'email@example.com';
                        break;
                    case 'Appwrite\Network\Validator\URL':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'url';
                        $node['x-example'] = 'https://example.com';
                        break;
                    case 'Utopia\Validator\JSON':
                    case 'Utopia\Validator\Mock':
                    case 'Utopia\Validator\Assoc':
                        $node['type'] = 'object';
                        $param['default'] = (empty($param['default'])) ? new \stdClass() : $param['default'];
                        $node['x-example'] = '{}';
                        //$node['format'] = 'json';
                        break;
                    case 'Utopia\Storage\Validator\File':
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
                        $node['type'] = $validator->getType();
                        $node['format'] = 'password';
                        $node['x-example'] = 'password';
                        break;
                    case 'Utopia\Validator\Range': /** @var \Utopia\Validator\Range $validator */
                        $node['type'] = $validator->getType() === Validator::TYPE_FLOAT ? 'number': $validator->getType();
                        $node['format'] = $validator->getType() == Validator::TYPE_INTEGER ? 'int32' : 'float';
                        $node['x-example'] = $validator->getMin();
                        break;
                    case 'Utopia\Validator\Numeric':
                    case 'Utopia\Validator\Integer':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'int32';
                        break;
                    case 'Utopia\Validator\Length':
                        $node['type'] = $validator->getType();
                        break;
                    case 'Appwrite\Network\Validator\Host':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'url';
                        $node['x-example'] = 'https://example.com';
                        break;
                    case 'Utopia\Validator\WhiteList': /** @var \Utopia\Validator\WhiteList $validator */
                        $node['type'] = $validator->getType();
                        $node['x-example'] = $validator->getList()[0];

                        if ($validator->getType() === 'integer') {
                            $node['format'] = 'int32';
                        }
                        break;
                    default:
                        $node['type'] = 'string';
                        break;
                }

                if ($param['optional'] && !\is_null($param['default'])) { // Param has default value
                    $node['default'] = $param['default'];
                }

                if (false !== \strpos($url, ':'.$name)) { // Param is in URL path
                    $node['in'] = 'path';
                    $temp['parameters'][] = $node;
                } elseif ($route->getMethod() == 'GET') { // Param is in query
                    $node['in'] = 'query';
                    $temp['parameters'][] = $node;
                } else { // Param is in payload

                    if (\in_array('multipart/form-data', $consumes)) {
                        $node['in'] = 'formData';
                        $temp['parameters'][] = $node;

                        continue;
                    }

                    if (!$param['optional']) {
                        $bodyRequired[] = $name;
                    }

                    $body['schema']['properties'][$name] = [
                        'type' => $node['type'],
                        'description' => $node['description'],
                        'default' => $node['default'] ?? null,
                        'x-example' => $node['x-example'] ?? null,
                    ];

                    if (\array_key_exists('items', $node)) {
                        $body['schema']['properties'][$name]['items'] = $node['items'];
                    }
                }

                $url = \str_replace(':'.$name, '{'.$name.'}', $url);
            }

            if (!empty($bodyRequired)) {
                $body['schema']['required'] = $bodyRequired;
            }

            if (!empty($body['schema']['properties'])) {
                $temp['parameters'][] = $body;
            }

            $temp['consumes'] = $consumes;

            $output['paths'][$url][\strtolower($route->getMethod())] = $temp;
        }
        foreach ($this->models as $model) {
            $this->getUsedModels($model, $usedModels);
        }

        foreach ($this->models as $model) {
            if (!in_array($model->getType(), $usedModels)) {
                continue;
            }

            $required = $model->getRequired();
            $rules = $model->getRules();

            $output['definitions'][$model->getType()] = [
                'description' => $model->getName(),
                'type' => 'object',
            ];

            if (!empty($rules)) {
                $output['definitions'][$model->getType()]['properties'] = [];
            }

            if ($model->isAny()) {
                $output['definitions'][$model->getType()]['additionalProperties'] = true;
            }

            if (!empty($required)) {
                $output['definitions'][$model->getType()]['required'] = $required;
            }

            foreach ($model->getRules() as $name => $rule) {
                $type = '';
                $format = null;
                $items = null;

                switch ($rule['type']) {
                    case 'string':
                    case 'json':
                        $type = 'string';
                        break;

                    case 'integer':
                        $type = 'integer';
                        $format = 'int32';
                        break;

                    case 'float':
                        $type = 'number';
                        $format = 'float';
                        break;

                    case 'double':
                        $type = 'number';
                        $format = 'double';
                        break;

                    case 'boolean':
                        $type = 'boolean';
                        break;

                    default:
                        $type = 'object';
                        $rule['type'] = ($rule['type']) ? $rule['type'] : 'none';

                        if (\is_array($rule['type'])) {
                            if ($rule['array']) {
                                $items = [
                                    'anyOf' => \array_map(function ($type) {
                                        return ['$ref' => '#/definitions/'.$type];
                                    }, $rule['type'])
                                ];
                            } else {
                                $items = [
                                    'oneOf' => \array_map(function ($type) {
                                        return ['$ref' => '#/definitions/'.$type];
                                    }, $rule['type'])
                                ];
                            }
                        } else {
                            $items = [
                                'type' => $type,
                                '$ref' => '#/definitions/'.$rule['type'],
                            ];
                        }
                        break;
                }

                if ($rule['array']) {
                    $output['definitions'][$model->getType()]['properties'][$name] = [
                        'type' => 'array',
                        'description' => $rule['description'] ?? '',
                        'items' => [
                            'type' => $type,
                        ],
                        'x-example' => $rule['example'] ?? null,
                    ];

                    if ($format) {
                        $output['definitions'][$model->getType()]['properties'][$name]['items']['format'] = $format;
                    }

                    if ($items) {
                        $output['definitions'][$model->getType()]['properties'][$name]['items'] = $items;
                    }
                } else {
                    $output['definitions'][$model->getType()]['properties'][$name] = [
                        'type' => $type,
                        'description' => $rule['description'] ?? '',
                        //'default' => $rule['default'] ?? null,
                        'x-example' => $rule['example'] ?? null,
                    ];

                    if ($format) {
                        $output['definitions'][$model->getType()]['properties'][$name]['format'] = $format;
                    }

                    if ($items) {
                        $output['definitions'][$model->getType()]['properties'][$name]['items'] = $items;
                    }
                }
            }
        }

        \ksort($output['paths']);

        return $output;
    }
}
