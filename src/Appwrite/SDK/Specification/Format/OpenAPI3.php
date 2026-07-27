<?php

namespace Appwrite\SDK\Specification\Format;

use Appwrite\Platform\Tasks\Specs;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
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
use Utopia\Http\Route;
use Utopia\Platform\Enum;
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
        $output = $this->buildBaseStructure();

        $usedModels = [];

        foreach ($this->routes as $route) {
            $this->processRoute($route, $output, $usedModels);
        }

        foreach ($this->models as $model) {
            $this->getNestedModels($model, $usedModels);
        }

        foreach ($this->models as $model) {
            if (!\in_array($model->getType(), $usedModels)) {
                continue;
            }

            $this->buildModelSchema($model, $output);
        }

        \ksort($output['paths']);

        return $output;
    }

    protected function buildBaseStructure(): array
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
                    'description' => 'Appwrite Cloud endpoint.',
                ],
                [
                    'url' => \str_replace('<REGION>', '{region}', $this->getParam('endpoint.docs', '')),
                    'description' => 'Appwrite Cloud regional endpoint. Replace `{region}` with your project region.',
                    'variables' => [
                        'region' => [
                            'default' => 'fra',
                            'description' => 'Appwrite Cloud region.',
                        ],
                    ],
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

        foreach ([
            'Project' => '<YOUR_PROJECT_ID>',
            'ProjectPath' => '<YOUR_PROJECT_ID>',
            'Key' => '<YOUR_API_KEY>',
            'JWT' => '<YOUR_JWT>',
            'Locale' => 'en',
            'Mode' => '',
        ] as $key => $demo) {
            if (isset($output['components']['securitySchemes'][$key])) {
                $output['components']['securitySchemes'][$key]['x-appwrite'] = \array_merge(
                    $output['components']['securitySchemes'][$key]['x-appwrite'] ?? [],
                    ['demo' => $demo]
                );
            }
        }

        return $output;
    }

    protected function processRoute(Route $route, array &$output, array &$usedModels): void
    {
        $url = \str_replace('/v1', '', $route->getPath());
        $scope = $route->getLabel('scope', '');

        $sdk = $route->getLabel('sdk', false);

        if ($sdk === false) {
            return;
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

        $methodName = $sdk->getMethodName();

        $desc = $sdk->getDescriptionFilePath() ?: $sdk->getDescription();
        $produces = ($sdk->getContentType())->value;
        $routeSecurity = $sdk->getAuth();

        $specs = new Specs();
        $sdkPlatforms = $specs->getSDKPlatformsForRouteSecurity($routeSecurity);

        $sdkPlatforms = array_values(array_unique($sdkPlatforms));
        $namespace = $sdk->getNamespace();

        $descContents = $this->getDescriptionContents($desc);

        $temp = $this->buildOperationTemplate($route, $sdk, $namespace, $methodName, $descContents, $sdkPlatforms);

        if (\is_array($additionalMethods) && \count($additionalMethods) > 0) {
            $this->processAdditionalMethods($additionalMethods, $route, $namespace, $temp, $usedModels, $specs);
        }

        $this->processResponses($sdk, $produces, $temp, $usedModels);

        if (!empty($scope)) {
            $this->processSecurity($sdk, $temp);
        }

        $parameterDataList = [];

        $parameters = $this->getMethodParameters($route, $sdk);

        foreach ($parameters as $name => $param) {
            if (($param['deprecated'] ?? false) === true) {
                continue;
            }

            $result = $this->buildParameterNode($name, $param, $sdk);
            $node = $result['node'];
            $parameter = $result['parameter'];

            if ($result['consumes'] !== null) {
                $consumes = [$result['consumes']];
            }

            $pathAliases = [$name, ...($param['aliases'] ?? [])];
            $pathAliasMap = \array_flip($pathAliases);
            $isPathParam = false;

            foreach (\explode('/', $url) as $segment) {
                if ($segment !== '' && $segment[0] === ':' && isset($pathAliasMap[\substr($segment, 1)])) {
                    $isPathParam = true;
                    break;
                }
            }

            $parameterDataList[] = [
                'name' => $name,
                'config' => $parameter,
                'node' => $node,
                'path' => $isPathParam,
            ];

            $segments = \explode('/', $url);
            foreach ($segments as &$segment) {
                if ($segment !== '' && $segment[0] === ':' && isset($pathAliasMap[\substr($segment, 1)])) {
                    $segment = '{' . $name . '}';
                }
            }
            unset($segment);
            $url = \implode('/', $segments);
        }

        $methods = \array_values($route->getMethods());
        $this->emitOperations($methods, $temp, $parameterDataList, $url, $consumes[0], $output);
    }

    protected function buildOperationTemplate(Route $route, Method $sdk, string $namespace, string $methodName, string $descContents, array $sdkPlatforms): array
    {
        $temp = [
            'summary' => $route->getDesc(),
            'operationId' => $namespace . \ucfirst($methodName),
            'tags' => [$namespace],
            'description' => $descContents,
            'responses' => [],
            'deprecated' => $sdk->isDeprecated(),
            'x-appwrite' => [
                'method' => $methodName,
                'group' => $sdk->getGroup(),
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

        return $temp;
    }

    protected function processAdditionalMethods(array $additionalMethods, Route $route, string $namespace, array &$temp, array &$usedModels, Specs $specs): void
    {
        $temp['x-appwrite']['methods'] = [];

        foreach ($additionalMethods as $methodObj) {
            /** @var Method $methodObj */
            $desc = $methodObj->getDescriptionFilePath();

            $methodSecurities = $methodObj->getAuth();
            $methodSdkPlatforms = $specs->getSDKPlatformsForRouteSecurity($methodSecurities);

            if (!\in_array($this->platform, $methodSdkPlatforms)) {
                continue;
            }

            $methodSecurities = [($methodObj->getLocationAuth()[0] ?? 'Project') => []];
            foreach ($methodObj->getAuth() as $security) {
                if (\array_key_exists($security->value, $this->keys)) {
                    $methodSecurities[$security->value] = [];
                }
            }

            $additionalMethod = [
                'name' => $methodObj->getMethodName(),
                'namespace' => $methodObj->getNamespace(),
                'desc' => $methodObj->getDesc(),
                'auth' => \array_slice($methodSecurities, 0, $this->authCount),
                'parameters' => [],
                'required' => [],
                'responses' => [],
                'description' => $this->getDescriptionContents($desc),
                'demo' => \strtolower($namespace) . '/' . Template::fromCamelCaseToDash($methodObj->getMethodName()) . '.md',
                'public' => $methodObj->isPublic(),
            ];

            if ($methodObj->getDeprecated()) {
                $additionalMethod['deprecated'] = [
                    'since' => $methodObj->getDeprecated()->getSince(),
                    'replaceWith' => $methodObj->getDeprecated()->getReplaceWith(),
                ];
            }

            if (empty($methodObj->getParameters())) {
                foreach ($route->getParams() as $name => $param) {
                    $additionalMethod['parameters'][] = $name;
                    if (!$param['optional']) {
                        $additionalMethod['required'][] = $name;
                    }
                }
            } else {
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
                                $usedModels[] = $modelName; // Reference needed
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

    protected function processResponses(Method $sdk, string $produces, array &$temp, array &$usedModels): void
    {
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

            if (\is_string($model)) {
                throw new \RuntimeException("Unresolved response model '{$model}' for method '{$sdk->getNamespace()}.{$sdk->getMethodName()}'. Ensure the model is registered.");
            }

            if (\is_array($model)) {
                foreach ($model as $m) {
                    if (\is_string($m)) {
                        throw new \RuntimeException("Unresolved response model '{$m}' for method '{$sdk->getNamespace()}.{$sdk->getMethodName()}'. Ensure the model is registered.");
                    }
                }
            }

            if (!(\is_array($model)) && $model->isNone()) {
                if ($produces === ContentType::TEXT->value && !\in_array($response->getCode(), [204, 301, 302, 308], true)) {
                    $temp['responses'][(string)$response->getCode()] = [
                        'description' => 'Text',
                        'content' => [
                            $produces => [
                                'schema' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ];

                    continue;
                }

                $temp['responses'][(string)$response->getCode()] = [
                    'description' => \in_array($produces, [
                        'image/*',
                        'image/jpeg',
                        'image/gif',
                        'image/png',
                        'image/svg+xml',
                        'image/webp',
                        'image/svg-x',
                        'image/x-icon',
                        'image/bmp',
                    ]) ? 'Image' : 'File',
                ];

                if ($produces !== '') {
                    $temp['responses'][(string)$response->getCode()]['content'] = [
                        $produces => [
                            'schema' => [
                                'type' => 'string',
                                'format' => 'binary',
                            ],
                        ],
                    ];
                }
            } else {
                if (\is_array($model)) {
                    $modelDescription = \join(', or ', \array_map(fn ($m) => $m->getName(), $model));

                    foreach ($model as $m) {
                        $usedModels[] = $m->getType();
                    }

                    $temp['responses'][(string)$response->getCode()] = [
                        'description' => $modelDescription,
                        'content' => [
                            $produces => [
                                'schema' => \array_filter([
                                    'oneOf' => \array_map(fn ($m) => ['$ref' => '#/components/schemas/' . $m->getType()], $model),
                                    'discriminator' => $this->getDiscriminator($model, '#/components/schemas/'),
                                ]),
                            ],
                        ],
                    ];
                } else {
                    $usedModels[] = $model->getType();
                    $temp['responses'][(string)$response->getCode()] = [
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

            if (\in_array($response->getCode(), [204, 301, 302, 308], true)) {
                $temp['responses'][(string)$response->getCode()]['description'] = 'No content';
            }

            if ($response->getCode() === 204) {
                unset($temp['responses'][(string)$response->getCode()]['content']);
            }
        }

        $hasResponseContent = false;
        foreach ($temp['responses'] as $responseData) {
            if (isset($responseData['content'])) {
                $hasResponseContent = true;
                break;
            }
        }

        if (!$hasResponseContent && $produces !== '') {
            $temp['x-appwrite']['produces'] = [$produces];
        }
    }

    protected function processSecurity(Method $sdk, array &$temp): void
    {
        $securities = [($sdk->getLocationAuth()[0] ?? 'Project') => []];

        foreach ($sdk->getAuth() as $security) {
            /** @var AuthType $security */
            if (\array_key_exists($security->value, $this->keys)) {
                $securities[$security->value] = [];
            }
        }

        $temp['x-appwrite']['auth'] = \array_slice($securities, 0, $this->authCount);

        if ($sdk->getType() === MethodType::LOCATION) {
            foreach ($sdk->getLocationAuth() as $key) {
                if (\array_key_exists($key, $this->keys)) {
                    $securities[$key] = [];
                    $temp['x-appwrite']['auth'][$key] = [];
                }
            }
        }

        $temp['security'][] = $securities;
    }

    /**
     * @return array{node: array, parameter: array, consumes: string|null}
     */
    protected function buildParameterNode(string $name, array $param, Method $sdk): array
    {
        /**
         * @var \Utopia\Validator $validator
         */
        $validator = $this->getValidator($param);

        $isNullable = $validator instanceof Nullable;

        $parameter = $this->getRequestParameterConfig(
            $param['optional'],
            $isNullable,
            $param['default'],
            $sdk->getNamespace() . '.' . $sdk->getMethodName(),
            $name,
        );

        $node = [
            'name' => $name,
            'description' => $param['description'],
            'required' => $parameter['required'],
        ];

        if ($isNullable) {
            /** @var Nullable $validator */
            $validator = $validator->getValidator();
        }

        $class = \get_class($validator);

        $base = \get_parent_class($class);

        switch ($base) {
            case \Appwrite\Utopia\Database\Validator\Queries\Base::class:
                $class = $base;
                break;
        }

        if ($class === \Utopia\Validator\AnyOf::class) {
            $validator = $param['validator']->getValidators()[0];
            $class = \get_class($validator);
        }

        $array = false;
        if ($class === \Utopia\Validator\ArrayList::class) {
            $array = true;
            $subclass = \get_class($validator->getValidator());
            switch ($subclass) {
                case \Appwrite\Utopia\Database\Validator\Operation::class:
                case \Utopia\Validator\WhiteList::class:
                    $class = $subclass;
                    break;
            }
        }

        $consumes = null;
        $schema = $this->resolveSchemaByValidator($class, $validator, $param, $array, $consumes, $node);

        if ($class === \Appwrite\Utopia\Database\Validator\CustomId::class && $sdk->getType() === MethodType::UPLOAD) {
            $schema['x-upload-id'] = true;
        }

        $node['schema'] = $schema;

        if ($parameter['emitDefault'] && $this->shouldEmitDefaultForSchema($param['default'], $node['schema'])) {
            $node['schema']['default'] = $param['default'];
        }

        return [
            'node' => $node,
            'parameter' => $parameter,
            'consumes' => $consumes,
        ];
    }

    protected function resolveSchemaByValidator(string $class, mixed $validator, array $param, bool $array, ?string &$consumes, array &$node): array
    {
        $schema = [];

        switch ($class) {
            case \Utopia\Database\Validator\UID::class:
            case \Utopia\Validator\Text::class:
                $schema['type'] = $validator->getType();
                $schema['x-example'] = ($param['example'] ?? '') ?: '<' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . '>';
                break;

            case \Utopia\Database\Validator\BigInt::class:
                $schema['type'] = 'integer';
                $schema['format'] = 'int64';
                if (!empty($param['example'])) {
                    $schema['x-example'] = $param['example'];
                }
                break;

            case \Utopia\Validator\Boolean::class:
                $schema['type'] = $validator->getType();
                $schema['x-example'] = ($param['example'] ?? '') ?: false;
                break;

            case \Appwrite\Utopia\Database\Validator\CustomId::class:
                $schema['type'] = $validator->getType();
                $schema['x-appwrite'] = [
                    'idGenerator' => 'ID.unique',
                ];
                $schema['x-example'] = ($param['example'] ?? '') ?: '<' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . '>';
                break;

            case \Utopia\Database\Validator\Datetime::class:
                $schema['type'] = $validator->getType();
                $schema['format'] = 'datetime';
                $schema['x-example'] = ($param['example'] ?? '') ?: Model::TYPE_DATETIME_EXAMPLE;
                break;

            case \Utopia\Database\Validator\Spatial::class:
                /** @var Spatial $validator */
                $schema['type'] = 'array';
                $schema['items'] = match ($validator->getSpatialType()) {
                    Database::VAR_POINT => [
                        'type' => 'number',
                        'format' => 'double',
                    ],
                    Database::VAR_LINESTRING => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'number',
                            'format' => 'double',
                        ],
                    ],
                    Database::VAR_POLYGON => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'number',
                                'format' => 'double',
                            ],
                        ],
                    ],
                    default => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'number',
                            'format' => 'double',
                        ],
                    ],
                };
                $schema['x-example'] = ($param['example'] ?? '') ?: match ($validator->getSpatialType()) {
                    Database::VAR_POINT => '[1, 2]',
                    Database::VAR_LINESTRING => '[[1, 2], [3, 4], [5, 6]]',
                    Database::VAR_POLYGON => '[[[1, 2], [3, 4], [5, 6], [1, 2]]]',
                    default => '',
                };
                break;

            case \Utopia\Emails\Validator\Email::class:
                $schema['type'] = $validator->getType();
                $schema['format'] = 'email';
                $schema['x-example'] = ($param['example'] ?? '') ?: 'email@example.com';
                break;

            case \Utopia\Validator\Host::class:
            case \Utopia\Validator\URL::class:
            case \Appwrite\Network\Validator\Redirect::class:
                $schema['type'] = $validator->getType();
                $schema['format'] = 'url';
                $schema['x-example'] = ($param['example'] ?? '') ?: 'https://example.com';
                break;

            case \Utopia\Validator\JSON::class:
            case \Utopia\Validator\Assoc::class:
                $schema['type'] = 'object';
                $schema['default'] = (empty($param['default'])) ? new \stdClass() : $param['default'];
                $schema['x-example'] = ($param['example'] ?? '') ?: '{}';
                break;

            case \Appwrite\Utopia\Request\Validator\File::class:
                $consumes = 'multipart/form-data';
                $schema['type'] = $validator->getType();
                $schema['format'] = 'binary';
                break;

            case \Utopia\Validator\ArrayList::class:
                /** @var ArrayList $validator */
                $schema['type'] = 'array';
                $schema['items'] = [
                    'type' => $validator->getValidator()->getType(),
                ];
                if (!empty($param['example'])) {
                    $schema['x-example'] = $param['example'];
                }
                break;

            case \Appwrite\Utopia\Database\Validator\Queries\Base::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Columns::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Attributes::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Buckets::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Tables::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Collections::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Databases::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Deployments::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Executions::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Files::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Functions::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Identities::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Indexes::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Installations::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Branches::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Memberships::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Messages::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Migrations::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Projects::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Providers::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Rules::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Subscribers::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Targets::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Teams::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Topics::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Users::class:
            case \Appwrite\Utopia\Database\Validator\Queries\Variables::class:
            case \Utopia\Database\Validator\Queries::class:
            case \Utopia\Database\Validator\Queries\Document::class:
            case \Utopia\Database\Validator\Queries\Documents::class:
                $schema['type'] = 'array';
                $schema['items'] = [
                    'type' => 'string',
                ];
                break;

            case \Utopia\Database\Validator\Permissions::class:
                $schema['type'] = $validator->getType();
                $schema['items'] = [
                    'type' => 'string',
                ];
                $schema['x-example'] = ($param['example'] ?? '') ?: '["' . Permission::read(Role::any()) . '"]';
                break;

            case \Utopia\Database\Validator\Roles::class:
                $schema['type'] = $validator->getType();
                $schema['items'] = [
                    'type' => 'string',
                ];
                $schema['x-example'] = ($param['example'] ?? '') ?: '["' . Role::any()->toString() . '"]';
                break;

            case \Appwrite\Auth\Validator\Password::class:
            case \Appwrite\SDK\Specification\Validator\PasswordFormat::class:
                $schema['type'] = $validator->getType();
                $schema['format'] = 'password';
                $schema['x-example'] = ($param['example'] ?? '') ?: 'password';
                break;

            case \Appwrite\Auth\Validator\Phone::class:
                $schema['type'] = $validator->getType();
                $schema['format'] = 'phone';
                $schema['x-example'] = ($param['example'] ?? '') ?: '+12065550100';
                break;

            case \Utopia\Validator\Range::class:
                /** @var Range $validator */
                $schema['type'] = $validator->getType() === Validator::TYPE_FLOAT ? 'number' : $validator->getType();
                $schema['format'] = $validator->getType() == Validator::TYPE_INTEGER ? 'int32' : 'float';
                $schema['x-example'] = ($param['example'] ?? '') ?: $validator->getMin();
                break;

            case \Utopia\Validator\Integer::class:
                $schema['type'] = $validator->getType();
                $schema['format'] = $validator->getFormat();
                if (!empty($param['example'])) {
                    $schema['x-example'] = $param['example'];
                }
                break;

            case \Utopia\Validator\Numeric::class:
            case \Utopia\Validator\FloatValidator::class:
                $schema['type'] = 'number';
                $schema['format'] = 'float';
                if (!empty($param['example'])) {
                    $schema['x-example'] = $param['example'];
                }
                break;

            case \Utopia\Validator\WhiteList::class:
                $schema = $this->resolveWhiteListSchema($validator, $param, $array, $node);
                break;

            case \Appwrite\Utopia\Database\Validator\CompoundUID::class:
                $schema['type'] = $validator->getType();
                $schema['x-example'] = ($param['example'] ?? '') ?: '<ID1:ID2>';
                break;

            case \Appwrite\Utopia\Database\Validator\Operation::class:
                $schema = $this->resolveOperationSchema($validator, $param, $array);
                break;

            default:
                $schema['type'] = 'string';
                if (!empty($param['example'])) {
                    $schema['x-example'] = $param['example'];
                }
                break;
        }

        return $schema;
    }

    protected function resolveWhiteListSchema(mixed $validator, array $param, bool $array, array &$node): array
    {
        $schema = [];

        if ($array) {
            $validator = $validator->getValidator();

            $schema['type'] = 'array';
            $schema['items'] = [
                'type' => $validator->getType(),
            ];
            if (!empty($param['example'])) {
                $schema['x-example'] = $param['example'];
            }

            if ($validator->getType() === 'string') {
                $enum = $param['enum'] ?? null;

                if ($enum instanceof Enum) {
                    $enumValues = \array_values($validator->getList());

                    if (!empty($enum->exclude)) {
                        $keepIndices = [];
                        foreach ($enumValues as $index => $value) {
                            if (!\in_array($value, $enum->exclude, true)) {
                                $keepIndices[] = $index;
                            }
                        }

                        $enumValues = \array_values(\array_intersect_key($enumValues, \array_flip($keepIndices)));
                        $node['description'] = $this->parseDescription($node['description'], $enum->exclude);
                    }

                    $enumKeys = [];
                    foreach ($enumValues as $enumValue) {
                        $enumKeys[] = $enum->map[$enumValue] ?? $enumValue;
                    }

                    $schema['items']['enum'] = $enumValues;
                    if (!empty($enum->name)) {
                        $schema['items']['x-enum-name'] = $enum->name;
                    }
                    $schema['items']['x-enum-keys'] = $enumKeys;
                }
            }
            if ($validator->getType() === 'integer') {
                $schema['items']['format'] = $validator->getFormat();
            }
        } else {
            $schema['type'] = $validator->getType();
            $schema['x-example'] = ($param['example'] ?? '') ?: $validator->getList()[0];

            if ($validator->getType() === 'string') {
                $enum = $param['enum'] ?? null;

                if ($enum instanceof Enum) {
                    $enumValues = \array_values($validator->getList());

                    if (!empty($enum->exclude)) {
                        $keepIndices = [];
                        foreach ($enumValues as $index => $value) {
                            if (!\in_array($value, $enum->exclude, true)) {
                                $keepIndices[] = $index;
                            }
                        }

                        $enumValues = \array_values(\array_intersect_key($enumValues, \array_flip($keepIndices)));
                        $node['description'] = $this->parseDescription($node['description'], $enum->exclude);
                    }

                    $enumKeys = [];
                    foreach ($enumValues as $enumValue) {
                        $enumKeys[] = $enum->map[$enumValue] ?? $enumValue;
                    }

                    $schema['enum'] = $enumValues;
                    if (!empty($enum->name)) {
                        $schema['x-enum-name'] = $enum->name;
                    }
                    $schema['x-enum-keys'] = $enumKeys;
                }
            }
            if ($validator->getType() === 'integer') {
                $schema['format'] = $validator->getFormat();
            }
        }

        return $schema;
    }

    protected function resolveOperationSchema(mixed $validator, array $param, bool $array): array
    {
        $schema = [];

        if ($array) {
            $validator = $validator->getValidator();
        }

        /** @var Operation $validator */
        $collectionIdKey = $validator->getCollectionIdKey();
        $documentIdKey = $validator->getDocumentIdKey();
        if ($array) {
            $schema['type'] = 'array';
            $schema['items'] = ['type' => 'object'];
        } else {
            $schema['type'] = 'object';
        }
        if (empty($param['example'])) {
            $example = [
                'action' => 'create',
                'databaseId' => '<DATABASE_ID>',
                $collectionIdKey => '<' . \strtoupper(Template::fromCamelCaseToSnake($collectionIdKey)) . '>',
                $documentIdKey => '<' . \strtoupper(Template::fromCamelCaseToSnake($documentIdKey)) . '>',
                'data' => [
                    'name' => 'Walter O\'Brien',
                ],
            ];
            if ($array) {
                $example = [$example];
            }
            $schema['x-example'] = \str_replace("\n", "\n\t", \json_encode($example, JSON_PRETTY_PRINT));
        } else {
            $schema['x-example'] = $param['example'];
        }

        return $schema;
    }

    protected function emitOperations(array $methods, array $temp, array $parameterDataList, string $url, string $consumes, array &$output): void
    {
        foreach ($methods as $index => $method) {
            $methodTemp = $temp;
            if (\count($methods) > 1) {
                $suffix = \ucfirst(\strtolower($method));
                $methodTemp['operationId'] .= $suffix;

                if ($index > 0) {
                    $methodTemp['x-appwrite']['method'] .= $suffix;
                }
            }

            $this->buildRequest($methodTemp, $parameterDataList, $method, $consumes);

            $output['paths'][$url][\strtolower($method)] = $methodTemp;
        }
    }

    protected function buildRequest(array &$methodTemp, array $parameterDataList, string $method, string $consumes): void
    {
        $body = [
            'content' => [
                $consumes => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ],
            ],
        ];
        $bodyRequired = [];

        foreach ($parameterDataList as $parameterNode) {
            $name = $parameterNode['name'];
            $parameter = $parameterNode['config'];
            $node = $parameterNode['node'];

            if ($parameterNode['path']) {
                $node['in'] = 'path';
                $methodTemp['parameters'][] = $node;
            } elseif (\in_array($method, ['GET', 'DELETE'], true)) {
                $node['in'] = 'query';
                $methodTemp['parameters'][] = $node;
            } else {
                if ($node['required']) {
                    $bodyRequired[] = $name;
                }

                $body['content'][$consumes]['schema']['properties'][$name] = [
                    'type' => $node['schema']['type'],
                    'description' => $node['description'],
                ];

                if (\array_key_exists('default', $node['schema'])) {
                    $body['content'][$consumes]['schema']['properties'][$name]['default'] = $node['schema']['default'];
                }

                $body['content'][$consumes]['schema']['properties'][$name]['x-example'] = $node['schema']['x-example'] ?? null;

                if (isset($node['schema']['format'])) {
                    $body['content'][$consumes]['schema']['properties'][$name]['format'] = $node['schema']['format'];
                }

                if (isset($node['schema']['enum'])) {
                    $body['content'][$consumes]['schema']['properties'][$name]['enum'] = $node['schema']['enum'];
                    $body['content'][$consumes]['schema']['properties'][$name]['x-enum-name'] = $node['schema']['x-enum-name'] ?? null;
                    $body['content'][$consumes]['schema']['properties'][$name]['x-enum-keys'] = $node['schema']['x-enum-keys'];
                }

                if ($node['schema']['x-upload-id'] ?? false) {
                    $body['content'][$consumes]['schema']['properties'][$name]['x-upload-id'] = $node['schema']['x-upload-id'];
                }

                if (isset($node['schema']['x-appwrite'])) {
                    $body['content'][$consumes]['schema']['properties'][$name]['x-appwrite'] = $node['schema']['x-appwrite'];
                }

                if (\array_key_exists('items', $node['schema'])) {
                    $body['content'][$consumes]['schema']['properties'][$name]['items'] = $node['schema']['items'];
                }

                if ($parameter['nullable']) {
                    $body['content'][$consumes]['schema']['properties'][$name]['x-nullable'] = true;
                }
            }
        }

        if (!empty($bodyRequired)) {
            $body['content'][$consumes]['schema']['required'] = $bodyRequired;
        }

        if (!empty($body['content'][$consumes]['schema']['properties'])) {
            $methodTemp['requestBody'] = $body;
        }
    }

    protected function buildModelSchema(Model $model, array &$output): void
    {
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
            if (($rule['hidden'] ?? false) === true) {
                continue;
            }

            $examples[$name] = $rule['example'] ?? null;

            $property = $this->buildModelProperty($name, $rule);

            $output['components']['schemas'][$model->getType()]['properties'][$name] = $property;

            if (!\in_array($name, $required) && !isset($property['additionalProperties'])) {
                $output['components']['schemas'][$model->getType()]['properties'][$name]['nullable'] = true;
            }
        }

        /** @var Any $model */
        if ($model->isAny() && !empty($model->getSampleData())) {
            $examples = \array_merge($examples, $model->getSampleData());
        }

        $output['components']['schemas'][$model->getType()]['example'] = $examples;
    }

    protected function buildModelProperty(string $name, array $rule): array
    {
        $type = '';
        $format = $rule['format'] ?? null;
        $items = null;

        switch ($rule['type']) {
            case 'string':
            case 'datetime':
            case 'payload':
                $type = 'string';
                break;

            case 'id':
                $type = 'string';
                break;

            case 'enum':
                $type = 'string';
                break;

            case 'json':
                $type = 'object';
                break;

            case 'array':
                $type = 'array';
                $items = $this->getArrayItemsSchema($rule['example'] ?? []);
                break;

            case 'integer':
                $type = 'integer';
                $format = $rule['format'] ?? 'int32';
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
                    $resolvedModels = \array_map(function (string $type) {
                        foreach ($this->models as $model) {
                            if ($model->getType() === $type) {
                                return $model;
                            }
                        }

                        throw new \RuntimeException("Unresolved model '{$type}'. Ensure the model is registered.");
                    }, $rule['type']);

                    if ($rule['array'] ?? false) {
                        $items = \array_filter([
                            'anyOf' => \array_map(function ($type) {
                                return ['$ref' => '#/components/schemas/' . $type];
                            }, $rule['type']),
                            'discriminator' => $this->getDiscriminator($resolvedModels, '#/components/schemas/'),
                        ]);
                    } else {
                        $items = \array_filter([
                            'oneOf' => \array_map(function ($type) {
                                return ['$ref' => '#/components/schemas/' . $type];
                            }, $rule['type']),
                            'discriminator' => $this->getDiscriminator($resolvedModels, '#/components/schemas/'),
                        ]);
                    }
                } else {
                    $items = [
                        '$ref' => '#/components/schemas/' . $rule['type'],
                    ];
                }
                break;
        }

        $readOnly = $rule['readOnly'] ?? false;

        if ($rule['type'] == 'json') {
            $property = [
                'type' => $type,
                'additionalProperties' => true,
                'description' => $rule['description'] ?? '',
                'x-example' => $rule['example'] ?? null,
            ];

            if ($readOnly) {
                $property['readOnly'] = true;
            }

            return $property;
        }

        if ($rule['array'] ?? false) {
            $property = [
                'type' => 'array',
                'description' => $rule['description'] ?? '',
                'items' => [
                    'type' => $type,
                ],
                'x-example' => $rule['example'] ?? null,
            ];

            if ($format) {
                $property['items']['format'] = $format;
            }
            if ($readOnly) {
                $property['readOnly'] = true;
            }
        } else {
            $property = [
                'type' => $type,
                'description' => $rule['description'] ?? '',
                'x-example' => $rule['example'] ?? null,
            ];

            if ($format) {
                $property['format'] = $format;
            }
            if ($readOnly) {
                $property['readOnly'] = true;
            }
        }

        if ($items) {
            if (($rule['array'] ?? false) || $rule['type'] === 'array') {
                $property['items'] = $items;
            } else {
                if (isset($items['$ref']) || isset($items['oneOf'])) {
                    $items = ['allOf' => [$items]];
                }
                $property = [
                    ...$property,
                    ...$items,
                ];
            }
        }

        if ($rule['type'] === 'enum' && !empty($rule['enum'])) {
            if ($rule['array'] ?? false) {
                $property['items']['enum'] = \array_values($rule['enum']);
                if (!empty($rule['enumSDKName'])) {
                    $property['items']['x-enum-name'] = $rule['enumSDKName'];
                }
            } else {
                $property['enum'] = \array_values($rule['enum']);
                if (!empty($rule['enumSDKName'])) {
                    $property['x-enum-name'] = $rule['enumSDKName'];
                }
            }
        }

        return $property;
    }
}
