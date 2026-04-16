<?php

namespace Appwrite\SDK\Specification\Format;

use Appwrite\Platform\Tasks\Specs;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response;
use Appwrite\SDK\Specification\Format;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\Validator\Operation;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\Any;
use Utopia\Database\Database;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Spatial;
use Utopia\Validator;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;

class OpenAPI3 extends Format
{
    public function getName(): string
    {
        return 'Open API 3';
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
                [
                    'url' => $this->getParam('endpoint.docs', ''),
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
            $output['components']['securitySchemes']['Project']['x-appwrite'] = ['demo' => '<YOUR_PROJECT_ID>'];
        }

        if (isset($output['components']['securitySchemes']['Key'])) {
            $output['components']['securitySchemes']['Key']['x-appwrite'] = ['demo' => '<YOUR_API_KEY>'];
        }

        if (isset($output['securityDefinitions']['JWT'])) {
            $output['securityDefinitions']['JWT']['x-appwrite'] = ['demo' => '<YOUR_JWT>'];
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

            $sdk = $route->getLabel('sdk', false);

            if (empty($sdk)) {
                continue;
            }

            $additionalMethods = null;
            if (\is_array($sdk)) {
                $additionalMethods = $sdk;
                $sdk = $sdk[0];
            }

            /**
             * @var Method $sdk
             */
            $consumes = [$sdk->getRequestType()->value];

            $methodName = $sdk->getMethodName() ?? \uniqid();

            $desc = $sdk->getDescriptionFilePath() ?: $sdk->getDescription();
            $produces = ($sdk->getContentType())->value;
            $routeSecurity = $sdk->getAuth() ?? [];

            $specs = new Specs();
            $sdkPlatforms = $specs->getSDKPlatformsForRouteSecurity($routeSecurity);

            $namespace = $sdk->getNamespace() ?? 'default';

            $desc ??= '';
            $descContents = \str_ends_with($desc, '.md') ? \file_get_contents($desc) : $desc;

            $temp = [
                'summary' => $route->getDesc(),
                'operationId' => $namespace . ucfirst($methodName),
                'tags' => [$namespace],
                'description' => $descContents,
                'responses' => [],
                'deprecated' => $sdk->isDeprecated(),
                'x-appwrite' => [ // Appwrite related metadata
                    'method' => $methodName,
                    'group' => $sdk->getGroup(),
                    'weight' => $route->getOrder(),
                    'cookies' => $route->getLabel('sdk.cookies', false),
                    'type' => $sdk->getType()->value ?? '',
                    'demo' => \strtolower($namespace) . '/' . Template::fromCamelCaseToDash($methodName) . '.md',
                    'rate-limit' => $route->getLabel('abuse-limit', 0),
                    'rate-time' => $route->getLabel('abuse-time', 3600),
                    'rate-key' => $route->getLabel('abuse-key', 'url:{url},ip:{ip}'),
                    'scope' => $route->getLabel('scope', ''),
                    'platforms' => $sdkPlatforms,
                    'packaging' => $sdk->isPackaging(),
                    'public' => $sdk->isPublic(),
                ],
            ];

            if ($sdk->getDescriptionFilePath() !== null) {
                $temp['x-appwrite']['edit'] = 'https://github.com/appwrite/appwrite/edit/master' . $sdk->getDescription();
            }

            if ($sdk->getDeprecated()) {
                $temp['x-appwrite']['deprecated'] = [
                    'since' => $sdk->getDeprecated()->getSince(),
                    'replaceWith' => $sdk->getDeprecated()->getReplaceWith(),
                ];
            }

            if (!empty($additionalMethods)) {
                $temp['x-appwrite']['methods'] = [];
                foreach ($additionalMethods as $methodObj) {
                    /** @var Method $methodObj */
                    $desc = $methodObj->getDescriptionFilePath();

                    $methodSecurities = $methodObj->getAuth();
                    $methodSdkPlatforms = $specs->getSDKPlatformsForRouteSecurity($methodSecurities);

                    if (!\in_array($this->platform, $methodSdkPlatforms)) {
                        continue;
                    }

                    $methodSecurities = ['Project' => []];
                    foreach ($methodObj->getAuth() as $security) {
                        if (\array_key_exists($security->value, $this->keys)) {
                            $methodSecurities[$security->value] = [];
                        }
                    }

                    $additionalMethod = [
                        'name' => $methodObj->getMethodName(),
                        'namespace' => $methodObj->getNamespace(),
                        'desc' => $methodObj->getDesc() ?? '',
                        'auth' => \array_slice($methodSecurities, 0, $this->authCount),
                        'parameters' => [],
                        'required' => [],
                        'responses' => [],
                        'description' => ($desc) ? \file_get_contents($desc) : '',
                        'demo' => \strtolower($namespace) . '/' . Template::fromCamelCaseToDash($methodObj->getMethodName()) . '.md',
                        'public' => $methodObj->isPublic(),
                    ];

                    // add deprecation only if method has it!
                    if ($methodObj->getDeprecated()) {
                        $additionalMethod['deprecated'] = [
                            'since' => $methodObj->getDeprecated()->getSince(),
                            'replaceWith' => $methodObj->getDeprecated()->getReplaceWith(),
                        ];
                    }

                    // If additional method has no parameters, inherit from route
                    if (empty($methodObj->getParameters())) {
                        foreach ($route->getParams() as $name => $param) {
                            $additionalMethod['parameters'][] = $name;
                            if (!$param['optional']) {
                                $additionalMethod['required'][] = $name;
                            }
                        }
                    } else {
                        // Use method's own parameters
                        foreach ($methodObj->getParameters() as $parameter) {
                            $additionalMethod['parameters'][] = $parameter->getName();
                            if (!$parameter->getOptional()) {
                                $additionalMethod['required'][] = $parameter->getName();
                            }
                        }
                    }

                    foreach ($methodObj->getResponses() as $response) {
                        /** @var Response|array $response */
                        $responseModel = $response->getModel();

                        if (\is_array($responseModel)) {
                            foreach ($responseModel as $modelName) {
                                foreach ($this->models as $value) {
                                    if ($value->getType() === $modelName) {
                                        $usedModels[] = $modelName;
                                        break;
                                    }
                                }
                            }
                            $additionalMethod['responses'][] = [
                                'code' => $response->getCode(),
                                'model' => \array_map(fn ($m) => '#/components/schemas/' . $m, $responseModel)
                            ];
                        } else {
                            $responseData = [
                                'code' => $response->getCode(),
                            ];

                            // lets not assume stuff here!
                            if ($response->getCode() !== 204) {
                                $responseData['model'] = '#/components/schemas/' . $responseModel;
                                foreach ($this->models as $value) {
                                    if ($value->getType() === $responseModel) {
                                        $usedModels[] = $responseModel;
                                        break;
                                    }
                                }
                            }

                            $additionalMethod['responses'][] = $responseData;
                        }
                    }

                    $temp['x-appwrite']['methods'][] = $additionalMethod;
                }
            }

            // Handle response models
            foreach ($sdk->getResponses() as $response) {
                /** @var Response $response */
                $model = $response->getModel();

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
                    $temp['responses'][(string)$response->getCode() ?? '500'] = [
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

                        $temp['responses'][(string)$response->getCode() ?? '500'] = [
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
                        $temp['responses'][(string)$response->getCode() ?? '500'] = [
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

                if (($response->getCode() ?? 500) === 204) {
                    $temp['responses'][(string)$response->getCode() ?? '500']['description'] = 'No content';
                    unset($temp['responses'][(string)$response->getCode() ?? '500']['schema']);
                }
            }

            if (!empty($scope)) {
                $securities = ['Project' => []];

                foreach ($sdk->getAuth() as $security) {
                    /** @var AuthType $security */
                    if (array_key_exists($security->value, $this->keys)) {
                        $securities[$security->value] = [];
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
                if (($param['deprecated'] ?? false) === true) {
                    continue;
                }

                /**
                 * @var \Utopia\Validator $validator
                 */
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

                $class = !empty($validator)
                    ? \get_class($validator)
                    : '';

                $base = !empty($class)
                    ? \get_parent_class($class)
                    : '';

                switch ($base) {
                    case 'Appwrite\Utopia\Database\Validator\Queries\Base':
                        $class = $base;
                        break;
                }

                if ($class === 'Utopia\Validator\AnyOf') {
                    $validator = $param['validator']->getValidators()[0];
                    $class = \get_class($validator);
                }

                $array = false;
                if ($class === 'Utopia\Validator\ArrayList') {
                    $array = true;
                    $subclass = \get_class($validator->getValidator());
                    switch ($subclass) {
                        case 'Appwrite\Utopia\Database\Validator\Operation':
                        case 'Utopia\Validator\WhiteList':
                            $class = $subclass;
                            break;
                    }
                }

                switch ($class) {
                    case 'Utopia\Database\Validator\UID':
                    case 'Utopia\Validator\Text':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: '<' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . '>';
                        break;
                    case 'Utopia\Validator\Boolean':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: false;
                        break;
                    case 'Appwrite\Utopia\Database\Validator\CustomId':
                        if ($sdk->getType() === MethodType::UPLOAD) {
                            $node['schema']['x-upload-id'] = true;
                        }
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: '<' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . '>';
                        break;
                    case 'Utopia\Database\Validator\DatetimeValidator':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'datetime';
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: Model::TYPE_DATETIME_EXAMPLE;
                        break;
                    case 'Utopia\Database\Validator\Spatial':
                        /** @var Spatial $validator */
                        $node['schema']['type'] = 'array';
                        $node['schema']['items'] = [
                            'oneOf' => [
                                ['type' => 'array']
                            ]
                        ];
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: match ($validator->getSpatialType()) {
                            Database::VAR_POINT => '[1, 2]',
                            Database::VAR_LINESTRING => '[[1, 2], [3, 4], [5, 6]]',
                            Database::VAR_POLYGON => '[[[1, 2], [3, 4], [5, 6], [1, 2]]]',
                        };
                        break;
                    case 'Appwrite\Network\Validator\Email':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'email';
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: 'email@example.com';
                        break;
                    case 'Utopia\Validator\Host':
                    case 'Utopia\Validator\URL':
                    case 'Appwrite\Network\Validator\Redirect':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'url';
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: 'https://example.com';
                        break;
                    case 'Utopia\Validator\JSON':
                    case 'Utopia\Validator\Mock':
                    case 'Utopia\Validator\Assoc':
                        $param['default'] = (empty($param['default'])) ? new \stdClass() : $param['default'];
                        $node['schema']['type'] = 'object';
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: '{}';
                        break;
                    case 'Utopia\Storage\Validator\File':
                        $consumes = ['multipart/form-data'];
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'binary';
                        break;
                    case 'Utopia\Validator\ArrayList':
                        /** @var ArrayList $validator */
                        $node['schema']['type'] = 'array';
                        $node['schema']['items'] = [
                            'type' => $validator->getValidator()->getType(),
                        ];
                        if (!empty($param['example'])) {
                            $node['schema']['x-example'] = $param['example'];
                        }
                        break;
                    case 'Appwrite\Utopia\Database\Validator\Queries\Base':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Columns':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Attributes':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Buckets':
                    case 'Appwrite\Utopia\Database\Validator\Queries\Tables':
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
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: '["' . Permission::read(Role::any()) . '"]';
                        break;
                    case 'Utopia\Database\Validator\Roles':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['items'] = [
                            'type' => 'string',
                        ];
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: '["' . Role::any()->toString() . '"]';
                        break;
                    case 'Appwrite\Auth\Validator\Password':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'password';
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: 'password';
                        break;
                    case 'Appwrite\Auth\Validator\Phone':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'phone';
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: '+12065550100'; // In the US, 555 is reserved like example.com
                        break;
                    case 'Utopia\Validator\Range':
                        /** @var Range $validator */
                        $node['schema']['type'] = $validator->getType() === Validator::TYPE_FLOAT ? 'number' : $validator->getType();
                        $node['schema']['format'] = $validator->getType() == Validator::TYPE_INTEGER ? 'int32' : 'float';
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: $validator->getMin();
                        break;
                    case 'Utopia\Validator\Integer':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'int32';
                        if (!empty($param['example'])) {
                            $node['schema']['x-example'] = $param['example'];
                        }
                        break;
                    case 'Utopia\Validator\Numeric':
                    case 'Utopia\Validator\FloatValidator':
                        $node['schema']['type'] = 'number';
                        $node['schema']['format'] = 'float';
                        if (!empty($param['example'])) {
                            $node['schema']['x-example'] = $param['example'];
                        }
                        break;
                    case 'Utopia\Validator\Length':
                        $node['schema']['type'] = $validator->getType();
                        if (!empty($param['example'])) {
                            $node['schema']['x-example'] = $param['example'];
                        }
                        break;
                    case 'Utopia\Validator\WhiteList':
                        if ($array) {
                            $validator = $validator->getValidator();

                            $node['schema']['type'] = 'array';
                            $node['schema']['items'] = [
                                'type' => $validator->getType(),
                            ];
                            if (!empty($param['example'])) {
                                $node['schema']['x-example'] = $param['example'];
                            }

                            // Iterate from the blackList. If it matches with the current one, then it is a blackList
                            // Do not add the enum
                            $allowed = true;
                            foreach ($this->enumBlacklist as $blacklist) {
                                if (
                                    $blacklist['namespace'] == $sdk->getNamespace()
                                    && $blacklist['method'] == $methodName
                                    && $blacklist['parameter'] == $name
                                ) {
                                    $allowed = false;
                                    break;
                                }
                            }
                            if ($allowed && $validator->getType() === 'string') {
                                $node['schema']['items']['enum'] = \array_values($validator->getList());
                                $node['schema']['items']['x-enum-name'] = $this->getRequestEnumName($sdk->getNamespace() ?? '', $methodName, $name);
                                $node['schema']['items']['x-enum-keys'] = $this->getRequestEnumKeys($sdk->getNamespace() ?? '', $methodName, $name);
                            }
                            if ($validator->getType() === 'integer') {
                                $node['schema']['items']['format'] = 'int32';
                            }
                        } else {
                            $node['schema']['type'] = $validator->getType();
                            $node['schema']['x-example'] = ($param['example'] ?? '') ?: $validator->getList()[0];

                            // Iterate from the blackList. If it matches with the current one, then it is a blackList
                            // Do not add the enum
                            $allowed = true;
                            foreach ($this->enumBlacklist as $blacklist) {
                                if (
                                    $blacklist['namespace'] == $sdk->getNamespace()
                                    && $blacklist['method'] == $methodName
                                    && $blacklist['parameter'] == $name
                                ) {
                                    $allowed = false;
                                    break;
                                }
                            }
                            if ($allowed && $validator->getType() === 'string') {
                                $node['schema']['enum'] = \array_values($validator->getList());
                                $node['schema']['x-enum-name'] = $this->getRequestEnumName($sdk->getNamespace() ?? '', $methodName, $name);
                                $node['schema']['x-enum-keys'] = $this->getRequestEnumKeys($sdk->getNamespace() ?? '', $methodName, $name);
                            }
                            if ($validator->getType() === 'integer') {
                                $node['format'] = 'int32';
                            }
                        }
                        break;
                    case 'Appwrite\Utopia\Database\Validator\CompoundUID':
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-example'] = ($param['example'] ?? '') ?: '<ID1:ID2>';
                        break;
                    case 'Appwrite\Utopia\Database\Validator\Operation':
                        if ($array) {
                            $validator = $validator->getValidator();
                        }

                        /** @var Operation $validator */
                        $collectionIdKey = $validator->getCollectionIdKey();
                        $documentIdKey = $validator->getDocumentIdKey();
                        if ($array) {
                            $node['schema']['type'] = 'array';
                            $node['schema']['items'] = ['type' => 'object'];
                        } else {
                            $node['schema']['type'] = 'object';
                        }
                        if (empty($param['example'])) {
                            $example = [
                                'action' => 'create',
                                'databaseId' => '<DATABASE_ID>',
                                $collectionIdKey => '<'.\strtoupper(Template::fromCamelCaseToSnake($collectionIdKey)).'>',
                                $documentIdKey => '<'.\strtoupper(Template::fromCamelCaseToSnake($documentIdKey)).'>',
                                'data' => [
                                    'name' => 'Walter O\'Brien',
                                ],
                            ];
                            if ($array) {
                                $example = [$example];
                            }
                            $node['schema']['x-example'] = \str_replace("\n", "\n\t", \json_encode($example, JSON_PRETTY_PRINT));
                        } else {
                            $node['schema']['x-example'] = $param['example'];
                        }
                        break;
                    default:
                        $node['schema']['type'] = 'string';
                        if (!empty($param['example'])) {
                            $node['schema']['x-example'] = $param['example'];
                        }
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

                    if (isset($node['schema']['enum'])) {
                        /// If the enum flag is Set, add the enum values to the body
                        $body['content'][$consumes[0]]['schema']['properties'][$name]['enum'] = $node['schema']['enum'];
                        $body['content'][$consumes[0]]['schema']['properties'][$name]['x-enum-name'] = $node['schema']['x-enum-name'] ?? null;
                        $body['content'][$consumes[0]]['schema']['properties'][$name]['x-enum-keys'] = $node['schema']['x-enum-keys'] ?? null;
                    }

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
            $examples = [];

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

                $examples[$name] = $rule['example'] ?? null;

                switch ($rule['type']) {
                    case 'string':
                    case 'datetime':
                    case 'payload':
                        $type = 'string';
                        break;

                    case 'enum':
                        $type = 'string';
                        break;

                    case 'json':
                        $type = 'object';
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['additionalProperties'] = true;
                        break;

                    case 'array':
                        $type = 'array';
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

                $readOnly = $rule['readOnly'] ?? false;
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
                    if ($readOnly) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['readOnly'] = true;
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
                    if ($readOnly) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['readOnly'] = true;
                    }
                }
                if ($items) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name]['items'] = $items;
                }
                if ($rule['type'] === 'enum' && !empty($rule['enum'])) {
                    if ($rule['array']) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['items']['enum'] = \array_values($rule['enum']);
                        $enumName = $this->getResponseEnumName($model->getType(), $name);
                        if ($enumName) {
                            $output['components']['schemas'][$model->getType()]['properties'][$name]['items']['x-enum-name'] = $enumName;
                        }
                    } else {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['enum'] = \array_values($rule['enum']);
                        $enumName = $this->getResponseEnumName($model->getType(), $name);
                        if ($enumName) {
                            $output['components']['schemas'][$model->getType()]['properties'][$name]['x-enum-name'] = $enumName;
                        }
                    }
                }
                if (!in_array($name, $required)) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name]['nullable'] = true;
                }
            }

            /** @var Any $model */
            if ($model->isAny() && !empty($model->getSampleData())) {
                $examples = array_merge($examples, $model->getSampleData());
            }

            $output['components']['schemas'][$model->getType()]['example'] = $examples;
        }

        \ksort($output['paths']);

        return $output;
    }
}
