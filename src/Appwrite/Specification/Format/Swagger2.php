<?php

namespace Appwrite\Specification\Format;

use Appwrite\Specification\Format;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Validator;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;

class Swagger2 extends Format
{
    public function getName(): string
    {
        return 'Swagger 2';
    }

    protected function getNestedModels(Model $model, array &$usedModels): void
    {
        foreach ($model->getRules() as $rule) {
            if (!in_array($model->getType(), $usedModels)) {
                continue;
            }

            if (\is_array($rule['type'])) {
                foreach ($rule['type'] as $ruleType) {
                    if (!in_array($ruleType, ['string', 'integer', 'boolean', 'json', 'float'])) {
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
                if (!in_array($rule['type'], ['string', 'integer', 'boolean', 'json', 'float'])) {
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
        /*
        * Specifications (v2.0):
        * https://github.com/OAI/OpenAPI-Specification/blob/main/versions/2.0.md
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
            $output['securityDefinitions']['Project']['x-appwrite'] = ['demo' => '<YOUR_PROJECT_ID>'];
        }

        if (isset($output['securityDefinitions']['Key'])) {
            $output['securityDefinitions']['Key']['x-appwrite'] = ['demo' => '<YOUR_API_KEY>'];
        }

        if (isset($output['securityDefinitions']['JWT'])) {
            $output['securityDefinitions']['JWT']['x-appwrite'] = ['demo' => '<YOUR_JWT>'];
        }

        if (isset($output['securityDefinitions']['Locale'])) {
            $output['securityDefinitions']['Locale']['x-appwrite'] = ['demo' => 'en'];
        }

        if (isset($output['securityDefinitions']['Mode'])) {
            $output['securityDefinitions']['Mode']['x-appwrite'] = ['demo' => ''];
        }

        $usedModels = [];

        foreach ($this->routes as $route) {
            /** @var \Utopia\Route $route */
            $url = \str_replace('/v1', '', $route->getPath());
            $scope = $route->getLabel('scope', '');
            $consumes = [$route->getLabel('sdk.request.type', 'application/json')];

            $method = $route->getLabel('sdk.method', \uniqid());
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
                $sdkPlatforms[] = APP_PLATFORM_SERVER;
                $sdkPlatforms[] = APP_PLATFORM_CLIENT;
            }

            $temp = [
                'summary' => $route->getDesc(),
                'operationId' => $route->getLabel('sdk.namespace', 'default') . ucfirst($method),
                'consumes' => [],
                'produces' => [],
                'tags' => [$route->getLabel('sdk.namespace', 'default')],
                'description' => ($desc) ? \file_get_contents($desc) : '',
                'responses' => [],
                'x-appwrite' => [ // Appwrite related metadata
                    'method' => $method,
                    'weight' => $route->getOrder(),
                    'cookies' => $route->getLabel('sdk.cookies', false),
                    'type' => $route->getLabel('sdk.methodType', ''),
                    'deprecated' => $route->getLabel('sdk.deprecated', false),
                    'demo' => Template::fromCamelCaseToDash($route->getLabel('sdk.namespace', 'default')) . '/' . Template::fromCamelCaseToDash($method) . '.md',
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

            if ($produces) {
                $temp['produces'][] = $produces;
            }

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

            if (!(\is_array($model)) &&  $model->isNone()) {
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
                    'schema' => [
                        'type' => 'file'
                    ],
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
                        'schema' => [
                            'x-oneOf' => \array_map(function ($m) {
                                return ['$ref' => '#/definitions/' . $m->getType()];
                            }, $model)
                        ],
                    ];
                } else {
                    // Response definition using one type
                    $usedModels[] = $model->getType();
                    $temp['responses'][(string)$route->getLabel('sdk.response.code', '500')] = [
                        'description' => $model->getName(),
                        'schema' => [
                            '$ref' => '#/definitions/' . $model->getType(),
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

            $parameters = \array_merge(
                $route->getParams(),
                $route->getLabel('sdk.parameters', []),
            );

            foreach ($parameters as $name => $param) { // Set params
                /** @var Validator $validator */
                $validator = (\is_callable($param['validator'])) ? call_user_func_array($param['validator'], $this->app->getResources($param['injections'])) : $param['validator'];

                $node = [
                    'name' => $name,
                    'description' => $param['description'],
                    'required' => !$param['optional'],
                ];

                $isNullable = $validator instanceof Nullable;

                if ($isNullable) {
                    /** @var Nullable $validator */
                    $validator = $validator->getValidator();
                }

                $validatorClass = (!empty($validator)) ? \get_class($validator) : '';
                if ($validatorClass === 'Utopia\Validator\AnyOf') {
                    $validator = $param['validator']->getValidators()[0];
                    $validatorClass = \get_class($validator);
                }

                switch ($validatorClass) {
                    case 'Utopia\Validator\Text':
                    case 'Utopia\Database\Validator\UID':
                        $node['type'] = $validator->getType();
                        $node['x-example'] = '<' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . '>';
                        break;
                    case 'Utopia\Validator\Boolean':
                        $node['type'] = $validator->getType();
                        $node['x-example'] = false;
                        break;
                    case 'Appwrite\Utopia\Database\Validator\CustomId':
                        if ($route->getLabel('sdk.methodType', '') === 'upload') {
                            $node['x-upload-id'] = true;
                        }
                        $node['type'] = $validator->getType();
                        $node['x-example'] = '<' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . '>';
                        break;
                    case 'Utopia\Database\Validator\DatetimeValidator':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'datetime';
                        $node['x-example'] = Model::TYPE_DATETIME_EXAMPLE;
                        break;
                    case 'Appwrite\Network\Validator\Email':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'email';
                        $node['x-example'] = 'email@example.com';
                        break;
                    case 'Utopia\Validator\Host':
                    case 'Utopia\Validator\URL':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'url';
                        $node['x-example'] = 'https://example.com';
                        break;
                    case 'Utopia\Validator\ArrayList':
                        /** @var ArrayList $validator */
                        $node['type'] = 'array';
                        $node['collectionFormat'] = 'multi';
                        $node['items'] = [
                            'type' => $validator->getValidator()->getType(),
                        ];
                        break;
                    case 'Utopia\Validator\JSON':
                    case 'Utopia\Validator\Mock':
                    case 'Utopia\Validator\Assoc':
                    case 'Appwrite\Functions\Validator\Payload':
                        $node['type'] = 'object';
                        $node['default'] = (empty($param['default'])) ? new \stdClass() : $param['default'];
                        $node['x-example'] = '{}';
                        break;
                    case 'Utopia\Storage\Validator\File':
                        $consumes = ['multipart/form-data'];
                        $node['type'] = 'file';
                        break;
                    case 'Appwrite\Functions\Validator\Payload':
                        $consumes = ['multipart/form-data'];
                        $node['type'] = 'payload';
                        break;
                    case 'Appwrite\Utopia\Database\Validator\Queries\Attributes':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Buckets':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Collections':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Databases':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Deployments':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Executions':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Files':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Functions':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Identities':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Indexes':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Installations':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Memberships':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Messages':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Migrations':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Projects':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Providers':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Rules':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Subscribers':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Targets':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Teams':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Topics':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Users':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Variables':
                    case 'Utopia\Database\Validator\Queries':
                    case 'Utopia\Database\Validator\Queries\Document':
                    case 'Utopia\Database\Validator\Queries\Documents':
                        $node['type'] = 'array';
                        $node['collectionFormat'] = 'multi';
                        $node['items'] = [
                            'type' => 'string',
                        ];
                        break;
                    case 'Utopia\Database\Validator\Permissions':
                        $node['type'] = $validator->getType();
                        $node['collectionFormat'] = 'multi';
                        $node['items'] = [
                            'type' => 'string',
                        ];
                        $node['x-example'] = '["' . Permission::read(Role::any()) . '"]';
                        break;
                    case 'Utopia\Database\Validator\Roles':
                        $node['type'] = $validator->getType();
                        $node['collectionFormat'] = 'multi';
                        $node['items'] = [
                            'type' => 'string',
                        ];
                        $node['x-example'] = '["' . Role::any()->toString() . '"]';
                        break;
                    case 'Appwrite\Auth\Validator\Password':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'password';
                        $node['x-example'] = 'password';
                        break;
                    case 'Appwrite\Auth\Validator\Phone':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'phone';
                        $node['x-example'] = '+12065550100';
                        break;
                    case 'Utopia\Validator\Range':
                        /** @var Range $validator */
                        $node['type'] = $validator->getType() === Validator::TYPE_FLOAT ? 'number' : $validator->getType();
                        $node['format'] = $validator->getType() == Validator::TYPE_INTEGER ? 'int32' : 'float';
                        $node['x-example'] = $validator->getMin();
                        break;
                    case 'Utopia\Validator\Numeric':
                    case 'Utopia\Validator\Integer':
                        $node['type'] = $validator->getType();
                        $node['format'] = 'int32';
                        break;
                    case 'Utopia\Validator\FloatValidator':
                        $node['type'] = 'number';
                        $node['format'] = 'float';
                        break;
                    case 'Utopia\Validator\Length':
                        $node['type'] = $validator->getType();
                        break;
                    case 'Utopia\Validator\WhiteList':
                        /** @var \Utopia\Validator\WhiteList $validator */
                        $node['type'] = $validator->getType();
                        $node['x-example'] = $validator->getList()[0];

                        //Iterate the blackList. If it matches with the current one, then it is blackListed
                        $allowed = true;
                        foreach ($this->enumBlacklist as $blacklist) {
                            if ($blacklist['namespace'] == $route->getLabel('sdk.namespace', '') && $blacklist['method'] == $method && $blacklist['parameter'] == $name) {
                                $allowed = false;
                                break;
                            }
                        }

                        if ($allowed && $validator->getType() === 'string') {
                            $node['enum'] = $validator->getList();
                            $node['x-enum-name'] = $this->getEnumName($route->getLabel('sdk.namespace', ''), $method, $name);
                            $node['x-enum-keys'] = $this->getEnumKeys($route->getLabel('sdk.namespace', ''), $method, $name);
                        }

                        if ($validator->getType() === 'integer') {
                            $node['format'] = 'int32';
                        }
                        break;
                    case 'Appwrite\Utopia\Database\Validator\CompoundUID':
                        $node['type'] = $validator->getType();
                        $node['x-example'] = '[ID1:ID2]';
                        break;
                    default:
                        $node['type'] = 'string';
                        break;
                }

                if ($param['optional'] && !\is_null($param['default'])) { // Param has default value
                    $node['default'] = $param['default'];
                }

                if (\str_contains($url, ':' . $name)) { // Param is in URL path
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

                    if (isset($node['enum'])) {
                        /// If the enum flag is Set, add the enum values to the body
                        $body['schema']['properties'][$name]['enum'] = $node['enum'];
                        $body['schema']['properties'][$name]['x-enum-name'] = $node['x-enum-name'] ?? null;
                        $body['schema']['properties'][$name]['x-enum-keys'] = $node['x-enum-keys'] ?? null;
                    }

                    if ($node['x-global'] ?? false) {
                        $body['schema']['properties'][$name]['x-global'] = true;
                    }

                    if ($isNullable) {
                        $body['schema']['properties'][$name]['x-nullable'] = true;
                    }

                    if (\array_key_exists('items', $node)) {
                        $body['schema']['properties'][$name]['items'] = $node['items'];
                    }
                }

                $url = \str_replace(':' . $name, '{' . $name . '}', $url);
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
            $this->getNestedModels($model, $usedModels);
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
                    case 'datetime':
                        $type = 'string';
                        break;

                    case 'json':
                        $type = 'object';
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
                        $rule['type'] = ($rule['type']) ?: 'none';

                        if (\is_array($rule['type'])) {
                            if ($rule['array']) {
                                $items = [
                                    'x-anyOf' => \array_map(fn ($type) =>  ['$ref' => '#/definitions/' . $type], $rule['type'])
                                ];
                            } else {
                                $items = [
                                    'x-oneOf' => \array_map(fn ($type) => ['$ref' => '#/definitions/' . $type], $rule['type'])
                                ];
                            }
                        } else {
                            $items = [
                                'type' => $type,
                                '$ref' => '#/definitions/' . $rule['type'],
                            ];
                        }
                        break;
                }

                if ($rule['type'] == 'json') {
                    $output['definitions'][$model->getType()]['properties'][$name] = [
                        'type' => $type,
                        'additionalProperties' => true,
                        'description' => $rule['description'] ?? '',
                        'x-example' => $rule['example'] ?? null,
                    ];
                    continue;
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
                } else {
                    $output['definitions'][$model->getType()]['properties'][$name] = [
                        'type' => $type,
                        'description' => $rule['description'] ?? '',
                        'x-example' => $rule['example'] ?? null,
                    ];

                    if ($format) {
                        $output['definitions'][$model->getType()]['properties'][$name]['format'] = $format;
                    }
                }
                if ($items) {
                    $output['definitions'][$model->getType()]['properties'][$name]['items'] = $items;
                }
                if (!in_array($name, $required)) {
                    $output['definitions'][$model->getType()]['properties'][$name]['x-nullable'] = true;
                }
            }
        }

        \ksort($output['paths']);

        return $output;
    }
}
