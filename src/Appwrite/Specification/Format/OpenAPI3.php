<?php

namespace Appwrite\Specification\Format;

use Appwrite\Specification\Format;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Http\Validator;
use Utopia\Http\Validator\Nullable;

class OpenAPI3 extends Format
{
    public function getName(): string
    {
        return 'Open API 3';
    }

    protected function getNestedModels(Model $model, array &$usedModels): void
    {
        foreach ($model->getRules() as $rule) {
            if (!in_array($model->getType(), $usedModels)) {
                continue;
            }

            if (\is_array($rule['type'])) {
                foreach ($rule['type'] as $ruleType) {
                    if (!in_array($ruleType, ['string', 'integer', 'boolean', 'json', 'float', 'double'])) {
                        $usedModels[] = $ruleType;

                        foreach ($this->models as $m) {
                            if ($m->getType() === $ruleType) {
                                $this->getNestedModels($m, $usedModels);
                                continue;
                            }
                        }
                    }
                }
            } else {
                if (!in_array($rule['type'], ['string', 'integer', 'boolean', 'json', 'float', 'double'])) {
                    $usedModels[] = $rule['type'];

                    foreach ($this->models as $m) {
                        if ($m->getType() === $rule['type']) {
                            $this->getNestedModels($m, $usedModels);
                            continue;
                        }
                    }
                }
            }
        }
    }

    public function parse(): array
    {
        /**
         * Specifications (v3.0.0):
         * https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.0.md
         */
        $output = [
            'openapi' => '3.0.0',
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
            'servers' => [
                [
                    'url' => $this->getParam('endpoint', ''),
                ],
            ],
            'paths' => [],
            'tags' => $this->services,
            'components' => [
                'schemas' => [],
                'securitySchemes' => $this->keys,
            ],
            'externalDocs' => [
                'description' => $this->getParam('docs.description'),
                'url' => $this->getParam('docs.url'),
            ],
        ];

        if (isset($output['components']['securitySchemes']['Project'])) {
            $output['components']['securitySchemes']['Project']['x-appwrite'] = ['demo' => '5df5acd0d48c2'];
        }

        if (isset($output['components']['securitySchemes']['Key'])) {
            $output['components']['securitySchemes']['Key']['x-appwrite'] = ['demo' => '919c2d18fb5d4...a2ae413da83346ad2'];
        }

        if (isset($output['securityDefinitions']['JWT'])) {
            $output['securityDefinitions']['JWT']['x-appwrite'] = ['demo' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...'];
        }

        if (isset($output['components']['securitySchemes']['Locale'])) {
            $output['components']['securitySchemes']['Locale']['x-appwrite'] = ['demo' => 'en'];
        }

        if (isset($output['components']['securitySchemes']['Mode'])) {
            $output['components']['securitySchemes']['Mode']['x-appwrite'] = ['demo' => ''];
        }

        $usedModels = [];

        foreach ($this->routes as $route) {
            $url = \str_replace('/v1', '', $route->getPath());
            $scope = $route->getLabel('scope', '');
            $hide = $route->getLabel('sdk.hide', false);
            $consumes = [$route->getLabel('sdk.request.type', 'application/json')];

            if ($hide) {
                continue;
            }

            $id = $route->getLabel('sdk.method', \uniqid());
            $desc = (!empty($route->getLabel('sdk.description', ''))) ? \realpath(__DIR__ . '/../../../../' . $route->getLabel('sdk.description', '')) : null;
            $produces = $route->getLabel('sdk.response.type', null);
            $model = $route->getLabel('sdk.response.model', 'none');
            $routeSecurity = $route->getLabel('sdk.auth', []);
            $sdkPlatforms = [];

            foreach ($routeSecurity as $value) {
                switch ($value) {
                    case APP_AUTH_TYPE_SESSION:
                        $sdkPlatforms[] = APP_PLATFORM_CLIENT;
                        break;
                    case APP_AUTH_TYPE_KEY:
                        $sdkPlatforms[] = APP_PLATFORM_SERVER;
                        break;
                    case APP_AUTH_TYPE_JWT:
                        $sdkPlatforms[] = APP_PLATFORM_SERVER;
                        break;
                    case APP_AUTH_TYPE_ADMIN:
                        $sdkPlatforms[] = APP_PLATFORM_CONSOLE;
                        break;
                }
            }

            if (empty($routeSecurity)) {
                $sdkPlatforms[] = APP_PLATFORM_CLIENT;
            }

            $temp = [
                'summary' => $route->getDesc(),
                'operationId' => $route->getLabel('sdk.namespace', 'default') . ucfirst($id),
                'tags' => [$route->getLabel('sdk.namespace', 'default')],
                'description' => ($desc) ? \file_get_contents($desc) : '',
                'responses' => [],
                'x-appwrite' => [ // Appwrite related metadata
                    'method' => $route->getLabel('sdk.method', \uniqid()),
                    'weight' => $route->getOrder(),
                    'cookies' => $route->getLabel('sdk.cookies', false),
                    'type' => $route->getLabel('sdk.methodType', ''),
                    'demo' => Template::fromCamelCaseToDash($route->getLabel('sdk.namespace', 'default')) . '/' . Template::fromCamelCaseToDash($id) . '.md',
                    'edit' => 'https://github.com/appwrite/appwrite/edit/master' . $route->getLabel('sdk.description', ''),
                    'rate-limit' => $route->getLabel('abuse-limit', 0),
                    'rate-time' => $route->getLabel('abuse-time', 3600),
                    'rate-key' => $route->getLabel('abuse-key', 'url:{url},ip:{ip}'),
                    'scope' => $route->getLabel('scope', ''),
                    'platforms' => $sdkPlatforms,
                    'packaging' => $route->getLabel('sdk.packaging', false),
                    'offline-model' => $route->getLabel('sdk.offline.model', ''),
                    'offline-key' => $route->getLabel('sdk.offline.key', ''),
                    'offline-response-key' => $route->getLabel('sdk.offline.response.key', '$id'),
                ],
            ];

            foreach ($this->models as $value) {
                if (\is_array($model)) {
                    $model = \array_map(fn ($m) => $m === $value->getType() ? $value : $m, $model);
                } else {
                    if ($value->getType() === $model) {
                        $model = $value;
                        break;
                    }
                }
            }

            if (!(\is_array($model)) && $model->isNone()) {
                $temp['responses'][(string)$route->getLabel('sdk.response.code', '500')] = [
                    'description' => in_array($produces, [
                        'image/*',
                        'image/jpeg',
                        'image/gif',
                        'image/png',
                        'image/webp',
                        'image/svg-x',
                        'image/x-icon',
                        'image/bmp',
                    ]) ? 'Image' : 'File',
                ];
            } else {
                if (\is_array($model)) {
                    $modelDescription = \join(', or ', \array_map(fn ($m) => $m->getName(), $model));

                    // model has multiple possible responses, we will use oneOf
                    foreach ($model as $m) {
                        $usedModels[] = $m->getType();
                    }

                    $temp['responses'][(string)$route->getLabel('sdk.response.code', '500')] = [
                        'description' => $modelDescription,
                        'content' => [
                            $produces => [
                                'schema' => [
                                    'oneOf' => \array_map(fn ($m) => ['$ref' => '#/components/schemas/' . $m->getType()], $model)
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
                                    '$ref' => '#/components/schemas/' . $model->getType(),
                                ],
                            ],
                        ],
                    ];
                }
            }

            if ($route->getLabel('sdk.response.code', 500) === 204) {
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
                'content' => [
                    $consumes[0]  => [
                        'schema'  => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                ],
            ];

            $bodyRequired = [];

            foreach ($route->getParams() as $name => $param) { // Set params
                /**
                 * @var \Utopia\Validator $validator
                 */
                $validator = (\is_callable($param['validator'])) ? call_user_func_array($param['validator'], $this->app->getResources($param['injections'])) : $param['validator'];

                $node = [
                    'name' => $name,
                    'description' => $param['description'],
                    'required' => !$param['optional'],
                ];

                foreach ($this->services as $service) {
                    if ($route->getLabel('sdk.namespace', 'default') === $service['name'] && in_array($name, $service['x-globalAttributes'] ?? [])) {
                        $node['x-global'] = true;
                    }
                }

                $isNullable = $validator instanceof Nullable;

                if ($isNullable) {
                    /** @var Nullable $validator */
                    $validator = $validator->getValidator();
                }

                switch ((!empty($validator)) ? \get_class($validator) : '') {
                    case 'Utopia\Validator\Text':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = '[' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . ']';
                        break;
                    case 'Utopia\Validator\Boolean':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = false;
                        break;
                    case 'Utopia\Database\Validator\UID':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = '[' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . ']';
                        break;
                    case 'Appwrite\Utopia\Database\Validator\CustomId':
                        if ($route->getLabel('sdk.methodType', '') === 'upload') {
                            $node['schema']['x-upload-id'] = true;
                        }
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = '[' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . ']';
                        break;
                    case 'Utopia\Database\Validator\DatetimeValidator':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'datetime';
                        $node['schema']['x-example'] = Model::TYPE_DATETIME_EXAMPLE;
                        break;
                    case 'Appwrite\Network\Validator\Email':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'email';
                        $node['schema']['x-example'] = 'email@example.com';
                        break;
                    case 'Utopia\Validator\URL':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'url';
                        $node['schema']['x-example'] = 'https://example.com';
                        break;
                    case 'Utopia\Validator\JSON':
                    case 'Utopia\Validator\Mock':
                    case 'Utopia\Validator\Assoc':
                        $param['default'] = (empty($param['default'])) ? new \stdClass() : $param['default'];
                        $node['schema']['type'] = 'object';
                        $node['schema']['x-example'] = '{}';
                        //$node['schema']['format'] = 'json';
                        break;
                    case 'Utopia\Storage\Validator\File':
                        $consumes = ['multipart/form-data'];
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'binary';
                        break;
                    case 'Utopia\Validator\ArrayList':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Buckets':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Collections':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Databases':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Deployments':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Documents':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Executions':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Files':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Functions':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Memberships':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Projects':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Teams':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Users':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Variables':
                    case 'Appwrite\Utopia\Database\Validator\Queries':
                        $node['schema']['type'] = 'array';
                        $node['schema']['items'] = [
                            'type' => 'string',
                        ];
                        break;
                    case 'Utopia\Database\Validator\Permissions':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['items'] = [
                            'type' => 'string',
                        ];
                        $node['schema']['x-example'] = '["' . Permission::read(Role::any()) . '"]';
                        break;
                    case 'Utopia\Database\Validator\Roles':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['items'] = [
                            'type' => 'string',
                        ];
                        $node['schema']['x-example'] = '["' . Role::any()->toString() . '"]';
                        break;
                    case 'Appwrite\Auth\Validator\Password':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'password';
                        $node['schema']['x-example'] = 'password';
                        break;
                    case 'Appwrite\Auth\Validator\Phone':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'phone';
                        $node['schema']['x-example'] = '+12065550100'; // In the US, 555 is reserved like example.com
                        break;
                    case 'Utopia\Validator\Range':
                        /** @var \Utopia\Validator\Range $validator */
                        $node['schema']['type'] = $validator->getType() === Validator::TYPE_FLOAT ? 'number' : $validator->getType();
                        $node['schema']['format'] = $validator->getType() == Validator::TYPE_INTEGER ? 'int32' : 'float';
                        $node['schema']['x-example'] = $validator->getMin();
                        break;
                    case 'Utopia\Validator\Numeric':
                    case 'Utopia\Validator\Integer':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'int32';
                        break;
                    case 'Utopia\Validator\FloatValidator':
                        $node['schema']['type'] = 'number';
                        $node['schema']['format'] = 'float';
                        break;
                    case 'Utopia\Validator\Length':
                        $node['schema']['type'] = $validator->getType();
                        break;
                    case 'Utopia\Validator\Host':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'url';
                        $node['schema']['x-example'] = 'https://example.com';
                        break;
                    case 'Utopia\Validator\WhiteList':
                        /** @var \Utopia\Validator\WhiteList $validator */
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = $validator->getList()[0];

                        if ($validator->getType() === 'integer') {
                            $node['format'] = 'int32';
                        }
                        break;
                    default:
                        $node['schema']['type'] = 'string';
                        break;
                }

                if ($param['optional'] && !\is_null($param['default'])) { // Param has default value
                    $node['schema']['default'] = $param['default'];
                }

                if (false !== \strpos($url, ':' . $name)) { // Param is in URL path
                    $node['in'] = 'path';
                    $temp['parameters'][] = $node;
                } elseif ($route->getMethod() == 'GET') { // Param is in query
                    $node['in'] = 'query';
                    $temp['parameters'][] = $node;
                } else { // Param is in payload
                    if (!$param['optional']) {
                        $bodyRequired[] = $name;
                    }

                    $body['content'][$consumes[0]]['schema']['properties'][$name] = [
                        'type' => $node['schema']['type'],
                        'description' => $node['description'],
                        'x-example' => $node['schema']['x-example'] ?? null
                    ];

                    if ($node['schema']['x-upload-id'] ?? false) {
                        $body['content'][$consumes[0]]['schema']['properties'][$name]['x-upload-id'] = $node['schema']['x-upload-id'];
                    }

                    if (isset($node['default'])) {
                        $body['content'][$consumes[0]]['schema']['properties'][$name]['default'] = $node['default'];
                    }

                    if (\array_key_exists('items', $node['schema'])) {
                        $body['content'][$consumes[0]]['schema']['properties'][$name]['items'] = $node['schema']['items'];
                    }

                    if ($node['x-global'] ?? false) {
                        $body['content'][$consumes[0]]['schema']['properties'][$name]['x-global'] = true;
                    }

                    if ($isNullable) {
                        $body['content'][$consumes[0]]['schema']['properties'][$name]['x-nullable'] = true;
                    }
                }

                $url = \str_replace(':' . $name, '{' . $name . '}', $url);
            }

            if (!empty($bodyRequired)) {
                $body['content'][$consumes[0]]['schema']['required'] = $bodyRequired;
            }

            if (!empty($body['content'][$consumes[0]]['schema']['properties'])) {
                $temp['requestBody'] = $body;
            }

            $output['paths'][$url][\strtolower($route->getMethod())] = $temp;
        }

        foreach ($this->models as $model) {
            $this->getNestedModels($model, $usedModels);
        }

        foreach ($this->models as $model) {
            if (!in_array($model->getType(), $usedModels) && $model->getType() !== 'error') {
                continue;
            }

            $required = $model->getRequired();
            $rules = $model->getRules();

            $output['components']['schemas'][$model->getType()] = [
                'description' => $model->getName(),
                'type' => 'object',
            ];

            if (!empty($rules)) {
                $output['components']['schemas'][$model->getType()]['properties'] = [];
            }

            if ($model->isAny()) {
                $output['components']['schemas'][$model->getType()]['additionalProperties'] = true;
            }

            if (!empty($required)) {
                $output['components']['schemas'][$model->getType()]['required'] = $required;
            }

            foreach ($model->getRules() as $name => $rule) {
                $type = '';
                $format = null;
                $items = null;

                switch ($rule['type']) {
                    case 'string':
                    case 'datetime':
                        $type = 'string';
                        break;

                    case 'json':
                        $type = 'object';
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['additionalProperties'] = true;
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
                                        return ['$ref' => '#/components/schemas/' . $type];
                                    }, $rule['type'])
                                ];
                            } else {
                                $items = [
                                    'oneOf' => \array_map(function ($type) {
                                        return ['$ref' => '#/components/schemas/' . $type];
                                    }, $rule['type'])
                                ];
                            }
                        } else {
                            $items = [
                                '$ref' => '#/components/schemas/' . $rule['type'],
                            ];
                        }
                        break;
                }

                if ($rule['array']) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name] = [
                        'type' => 'array',
                        'description' => $rule['description'] ?? '',
                        'items' => [
                            'type' => $type,
                        ],
                        'x-example' => $rule['example'] ?? null,
                    ];

                    if ($format) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['items']['format'] = $format;
                    }
                } else {
                    $output['components']['schemas'][$model->getType()]['properties'][$name] = [
                        'type' => $type,
                        'description' => $rule['description'] ?? '',
                        'x-example' => $rule['example'] ?? null,
                    ];

                    if ($format) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['format'] = $format;
                    }
                }
                if ($items) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name]['items'] = $items;
                }
                if (!in_array($name, $required)) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name]['nullable'] = true;
                }
            }
        }

        \ksort($output['paths']);

        return $output;
    }
}
