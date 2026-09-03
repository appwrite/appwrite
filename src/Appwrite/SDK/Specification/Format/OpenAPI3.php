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
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Spatial;
use Utopia\Platform\Enum;
use Utopia\Validator;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

class OpenAPI3 extends Format
{
    public function getName(): string
    {
        return 'Open API 3';
    }

    private function normalizeExample(mixed $example, string $type): mixed
    {
        return match ($type) {
            'array' => $this->normalizeArrayExample($example),
            'object' => $this->normalizeObjectExample($example),
            'integer' => \is_int($example) ? $example : (int) $example,
            'number' => \is_int($example) || \is_float($example) ? $example : (float) $example,
            'boolean' => \is_bool($example) ? $example : \filter_var($example, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'string' => \is_string($example) ? $example : (string) $example,
            default => $example,
        };
    }

    private function normalizeArrayExample(mixed $example): array
    {
        if (\is_array($example)) {
            return \array_is_list($example) ? $example : [$example];
        }

        if (\is_object($example)) {
            $example = (array) $example;
            return empty($example) ? [] : [$example];
        }

        if (\is_string($example)) {
            if ($example === '') {
                return [];
            }

            try {
                $decoded = \json_decode($example, true, flags: JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    return \array_is_list($decoded) ? $decoded : [$decoded];
                }
            } catch (\JsonException) {
                // A scalar example for an array parameter represents one item.
            }
        }

        return [$example];
    }

    private function normalizeObjectExample(mixed $example): object
    {
        if (\is_object($example)) {
            return $example;
        }

        if (\is_array($example)) {
            if (!empty($example) && \array_is_list($example)) {
                throw new \InvalidArgumentException('Object schema examples cannot be lists.');
            }

            return (object) $example;
        }

        if (\is_string($example)) {
            try {
                $decoded = \json_decode($example, flags: JSON_THROW_ON_ERROR);
                if (\is_object($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
                // Throw the schema-specific error below.
            }
        }

        throw new \InvalidArgumentException('Object schema examples must be JSON objects.');
    }

    /**
     * @param list<string> $values
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function getEnumSchema(array $values, ?string $name, string $fallbackName, array $keys, bool $open = false): array
    {
        $this->assertEnumName($name ?: $fallbackName);

        $branches = [];
        foreach ($values as $index => $value) {
            $branch = [
                'type' => Validator::TYPE_STRING,
                'enum' => [$value],
            ];

            $key = $keys[$index] ?? null;
            if (\is_string($key) && $key !== '') {
                $branch['title'] = $key;
            }

            $branches[] = $branch;
        }

        $enum = [
            'type' => Validator::TYPE_STRING,
            'oneOf' => $branches,
        ];
        if (\is_string($name) && $name !== '') {
            $enum = ['title' => $name, ...$enum];
        }

        return $open
            ? [
                'type' => Validator::TYPE_STRING,
                'anyOf' => [$enum, ['type' => Validator::TYPE_STRING]],
            ]
            : $enum;
    }

    private function assertEnumName(string $enum): void
    {
        $normalizedEnum = $this->normalizeSdkName($enum);

        foreach ($this->services as $service) {
            $name = $service['name'] ?? null;
            if (!\is_string($name) || $name === '') {
                continue;
            }

            if ($this->normalizeSdkName($name) === $normalizedEnum) {
                throw new \RuntimeException(
                    "Spec service name '{$name}' must not overlap enum '{$enum}'."
                );
            }
        }
    }

    private function normalizeSdkName(string $name): string
    {
        return \strtolower((string) \preg_replace('/[^a-z0-9]/i', '', $name));
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
            'Organization' => '<YOUR_ORGANIZATION_ID>',
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

        $usedModels = [];

        foreach ($this->routes as $route) {
            $url = \str_replace('/v1', '', $route->getPath());
            $scope = $route->getLabel('scope', '');

            $sdk = $route->getLabel('sdk', false);

            if ($sdk === false) {
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

            $methodName = $sdk->getMethodName();

            $desc = $sdk->getDescriptionFilePath() ?: $sdk->getDescription();
            $produces = ($sdk->getContentType())->value;
            $routeSecurity = $sdk->getAuth();

            $specs = new Specs();
            $sdkPlatforms = $specs->getSDKPlatformsForRouteSecurity($routeSecurity);

            $sdkPlatforms = array_values(array_unique($sdkPlatforms));
            $namespace = $sdk->getNamespace();

            $descContents = $this->getDescriptionContents($desc);

            $temp = [
                'summary' => $route->getDesc(),
                'operationId' => $namespace . ucfirst($methodName),
                'tags' => [$namespace],
                'description' => $descContents,
                'responses' => [],
                'deprecated' => $sdk->isDeprecated(),
                'x-appwrite' => [ // Appwrite related metadata
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

            if (\is_array($additionalMethods) && \count($additionalMethods) > 0) {
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
                        'description' => in_array($produces, [
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

                        // model has multiple possible responses, we will use oneOf
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
                        // Response definition using one type
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

                if (in_array($response->getCode(), [204, 301, 302, 308], true)) {
                    $temp['responses'][(string)$response->getCode()]['description'] = 'No content';
                }

                if ($response->getCode() === 204) {
                    unset($temp['responses'][(string)$response->getCode()]['content']);
                }
            }

            // No response declares content (e.g. 204 No content): keep the produced
            // content type available for SDK generation.
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

            if (!empty($scope)) {
                $securities = [($sdk->getLocationAuth()[0] ?? 'Project') => []];

                foreach ($sdk->getAuth() as $security) {
                    /** @var AuthType $security */
                    if (array_key_exists($security->value, $this->keys)) {
                        $securities[$security->value] = [];
                    }
                }

                $temp['x-appwrite']['auth'] = array_slice($securities, 0, $this->authCount);

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

            $parameterNodes = [];

            $parameters = $this->getMethodParameters($route, $sdk);

            foreach ($parameters as $name => $param) { // Set params
                if (($param['deprecated'] ?? false) === true) {
                    continue;
                }

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

                // Every Queries validator serialises to an array of query strings, so
                // normalise the whole hierarchy instead of enumerating each subclass.
                if (\is_subclass_of($class, Queries::class)) {
                    $class = Queries::class;
                }

                $openEnum = false;
                if ($class === \Utopia\Validator\AnyOf::class) {
                    $validators = $param['validator']->getValidators();
                    $validator = $validators[0];
                    $class = \get_class($validator);

                    foreach ($validators as $unionValidator) {
                        while ($unionValidator instanceof ArrayList || $unionValidator instanceof Nullable) {
                            $unionValidator = $unionValidator->getValidator();
                        }

                        if (!$unionValidator instanceof WhiteList && $unionValidator->getType() === Validator::TYPE_STRING) {
                            $openEnum = true;
                            break;
                        }
                    }
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

                switch ($class) {
                    case \Utopia\Database\Validator\UID::class:
                    case \Utopia\Database\Validator\Key::class:
                    case \Appwrite\Utopia\Database\Validator\ProjectId::class:
                    case \Appwrite\Platform\Modules\Compute\Validator\VariableKey::class:
                    case \Utopia\Validator\Text::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '<' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . '>';
                        break;
                    case \Utopia\Database\Validator\BigInt::class:
                        // BigInt validator reports Database::VAR_BIGINT, but OpenAPI expects scalar types.
                        // We expose it as int64 to keep schema consistent with Column/Attribute models.
                        $node['schema']['type'] = 'integer';
                        $node['schema']['format'] = 'int64';
                        if (($param['example'] ?? '') !== '') {
                            $node['schema']['example'] = $param['example'];
                        }
                        break;
                    case \Utopia\Validator\Boolean::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : false;
                        break;
                    case \Appwrite\Utopia\Database\Validator\CustomId::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['x-appwrite'] = [
                            'idGenerator' => 'ID.unique',
                        ];
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '<' . \strtoupper(Template::fromCamelCaseToSnake($node['name'])) . '>';
                        break;
                    case \Appwrite\Task\Validator\Cron::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '0 0 * * *';
                        break;
                    case \Utopia\Validator\HexColor::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : 'FFFFFF';
                        break;
                    case \Utopia\Validator\Hostname::class:
                    case \Utopia\Domains\Validator\PublicDomain::class:
                    case \Utopia\Validator\Domain::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : 'example.com';
                        break;
                    case \Appwrite\Utopia\Database\Validator\Folder::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : 'photos/2026';
                        break;
                    case \Utopia\Database\Validator\Datetime::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'datetime';
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : Model::TYPE_DATETIME_EXAMPLE;
                        break;
                    case \Utopia\Database\Validator\Spatial::class:
                        /** @var Spatial $validator */
                        $node['schema']['type'] = 'array';
                        $node['schema']['items'] = match ($validator->getSpatialType()) {
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
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : match ($validator->getSpatialType()) {
                            Database::VAR_POINT => '[1, 2]',
                            Database::VAR_LINESTRING => '[[1, 2], [3, 4], [5, 6]]',
                            Database::VAR_POLYGON => '[[[1, 2], [3, 4], [5, 6], [1, 2]]]',
                            default => '',
                        };
                        break;
                    case \Utopia\Emails\Validator\Email::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'email';
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : 'email@example.com';
                        break;
                    case \Utopia\Validator\Host::class:
                    case \Utopia\Validator\URL::class:
                    case \Appwrite\Network\Validator\Redirect::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'url';
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : 'https://example.com';
                        break;
                    case \Utopia\Validator\Assoc::class:
                        // Assoc reports TYPE_ARRAY, so only an explicit case publishes
                        // it as an object. TYPE_OBJECT is handled by the default.
                        $node['schema']['type'] = 'object';
                        $node['schema']['default'] = (empty($param['default'])) ? new \stdClass() : $param['default'];
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '{}';
                        break;
                    case \Utopia\Validator\JSON\ArrayValidator::class:
                        $node['schema']['type'] = 'array';
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '[]';
                        break;
                    case \Appwrite\Utopia\Request\Validator\File::class:
                        $consumes = ['multipart/form-data'];
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'binary';
                        break;
                    case \Utopia\Validator\ArrayList::class:
                        /** @var ArrayList $validator */
                        $node['schema']['type'] = 'array';
                        // Validator::TYPE_FLOAT is gettype()'s 'double', and TYPE_MIXED has no
                        // OpenAPI equivalent at all. Emitting either verbatim produces a schema
                        // no OpenAPI parser accepts, so an SDK cannot be generated from the spec.
                        $itemType = $validator->getValidator()->getType();
                        $node['schema']['items'] = match ($itemType) {
                            Validator::TYPE_FLOAT => ['type' => 'number', 'format' => 'double'],
                            Validator::TYPE_MIXED => new \stdClass(),
                            default => ['type' => $itemType],
                        };
                        if (($param['example'] ?? '') !== '') {
                            $node['schema']['example'] = $param['example'];
                        }
                        break;
                    case Queries::class:
                        $node['schema']['type'] = 'array';
                        $node['schema']['items'] = [
                            'type' => 'string',
                        ];
                        break;
                    case \Utopia\Database\Validator\Permissions::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['items'] = [
                            'type' => 'string',
                        ];
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : [Permission::read(Role::any())];
                        break;
                    case \Utopia\Database\Validator\Roles::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['items'] = [
                            'type' => 'string',
                        ];
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '["' . Role::any()->toString() . '"]';
                        break;
                    case \Appwrite\Auth\Validator\Password::class:
                    case \Appwrite\SDK\Specification\Validator\PasswordFormat::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'password';
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : 'password';
                        break;
                    case \Appwrite\Auth\Validator\Phone::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = 'phone';
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '+12065550100'; // In the US, 555 is reserved like example.com
                        break;
                    case \Utopia\Validator\Range::class:
                        /** @var Range $validator */
                        $node['schema']['type'] = $validator->getType() === Validator::TYPE_FLOAT ? 'number' : $validator->getType();
                        $node['schema']['format'] = $validator->getType() == Validator::TYPE_INTEGER ? 'int32' : 'float';
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : $validator->getMin();
                        break;
                    case \Utopia\Validator\Integer::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['format'] = $validator->getFormat();
                        if (($param['example'] ?? '') !== '') {
                            $node['schema']['example'] = $param['example'];
                        }
                        break;
                    case \Utopia\Validator\Numeric::class:
                    case \Utopia\Validator\FloatValidator::class:
                        $node['schema']['type'] = 'number';
                        $node['schema']['format'] = 'float';
                        if (($param['example'] ?? '') !== '') {
                            $node['schema']['example'] = $param['example'];
                        }
                        break;
                    case \Utopia\Validator\WhiteList::class:
                        if ($array) {
                            $validator = $validator->getValidator();

                            $node['schema']['type'] = 'array';
                            $node['schema']['items'] = [
                                'type' => $validator->getType(),
                            ];
                            if (($param['example'] ?? '') !== '') {
                                $node['schema']['example'] = $param['example'];
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

                                    $node['schema']['items'] = $this->getEnumSchema(
                                        $enumValues,
                                        $enum->name,
                                        $name,
                                        $enumKeys,
                                        $openEnum,
                                    );
                                }
                            }
                            if ($validator->getType() === 'integer') {
                                $node['schema']['items']['format'] = $validator->getFormat();
                            }
                        } else {
                            $node['schema']['type'] = $validator->getType();
                            $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : $validator->getList()[0];

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

                                    $node['schema'] = [
                                        ...$node['schema'],
                                        ...$this->getEnumSchema(
                                            $enumValues,
                                            $enum->name,
                                            $name,
                                            $enumKeys,
                                            $openEnum,
                                        ),
                                    ];
                                }
                            }
                            if ($validator->getType() === 'integer') {
                                $node['schema']['format'] = $validator->getFormat();
                            }
                        }
                        break;
                    case \Appwrite\Utopia\Database\Validator\CompoundUID::class:
                        $node['schema']['type'] = $validator->getType();
                        $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '<ID1:ID2>';
                        break;
                    case \Appwrite\Utopia\Database\Validator\Operation::class:
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
                        if (($param['example'] ?? '') === '') {
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
                            $node['schema']['example'] = \str_replace("\n", "\n\t", \json_encode($example, JSON_PRETTY_PRINT));
                        } else {
                            $node['schema']['example'] = $param['example'];
                        }
                        break;
                    default:
                        if ($validator->getType() === Validator::TYPE_OBJECT) {
                            $node['schema']['type'] = 'object';
                            $node['schema']['default'] = empty($param['default']) ? new \stdClass() : $param['default'];
                            $node['schema']['example'] = ($param['example'] ?? '') !== '' ? $param['example'] : '{}';
                            break;
                        }

                        $node['schema']['type'] = 'string';
                        if (($param['example'] ?? '') !== '') {
                            $node['schema']['example'] = $param['example'];
                        }
                        break;
                }

                if (\array_key_exists('example', $node['schema'])) {
                    $node['schema']['example'] = $this->normalizeExample($node['schema']['example'], $node['schema']['type']);
                }

                if ($parameter['emitDefault'] && $this->shouldEmitDefaultForSchema($param['default'], $node['schema'])) { // Param has default value
                    $node['schema']['default'] = $param['default'];
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

                $parameterNodes[] = [
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
            foreach ($methods as $index => $method) {
                $methodTemp = $temp;
                if (\count($methods) > 1 && $index > 0) {
                    $suffix = \ucfirst(\strtolower($method));
                    $methodTemp['operationId'] .= $suffix;
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

                foreach ($parameterNodes as $parameterNode) {
                    $name = $parameterNode['name'];
                    $parameter = $parameterNode['config'];
                    $node = $parameterNode['node'];

                    if ($parameterNode['path']) { // Param is in URL path (directly or through alias)
                        $node['in'] = 'path';
                        // A route only matches when every path segment is present, so a
                        // path parameter is always supplied whatever the PHP param says.
                        // OpenAPI requires `required: true` here, and generators emit a
                        // bare identifier for the path substitution — an optional one
                        // becomes an undefined reference (Go) or interpolates the
                        // absent value into the URL (Python).
                        $node['required'] = true;
                        $methodTemp['parameters'][] = $node;
                    } elseif (\in_array($method, ['GET', 'DELETE'], true)) { // Param is in query
                        $node['in'] = 'query';
                        $methodTemp['parameters'][] = $node;
                    } else { // Param is in payload
                        if ($node['required']) {
                            $bodyRequired[] = $name;
                        }

                        $body['content'][$consumes[0]]['schema']['properties'][$name] = [
                            'description' => $node['description'],
                        ];
                        if (isset($node['schema']['type'])) {
                            $body['content'][$consumes[0]]['schema']['properties'][$name]['type'] = $node['schema']['type'];
                        }

                        if (\array_key_exists('default', $node['schema'])) {
                            $body['content'][$consumes[0]]['schema']['properties'][$name]['default'] = $node['schema']['default'];
                        }

                        if (\array_key_exists('example', $node['schema'])) {
                            $body['content'][$consumes[0]]['schema']['properties'][$name]['example'] = $node['schema']['example'];
                        }

                        if (isset($node['schema']['format'])) {
                            $body['content'][$consumes[0]]['schema']['properties'][$name]['format'] = $node['schema']['format'];
                        }

                        if (isset($node['schema']['oneOf']) || isset($node['schema']['anyOf'])) {
                            foreach (['title', 'oneOf', 'anyOf'] as $key) {
                                if (isset($node['schema'][$key])) {
                                    $body['content'][$consumes[0]]['schema']['properties'][$name][$key] = $node['schema'][$key];
                                }
                            }
                        }

                        if (isset($node['schema']['x-appwrite'])) {
                            $body['content'][$consumes[0]]['schema']['properties'][$name]['x-appwrite'] = $node['schema']['x-appwrite'];
                        }

                        if (\array_key_exists('items', $node['schema'])) {
                            $body['content'][$consumes[0]]['schema']['properties'][$name]['items'] = $node['schema']['items'];
                        }

                        if ($parameter['nullable']) {
                            $body['content'][$consumes[0]]['schema']['properties'][$name]['nullable'] = true;
                        }
                    }
                }

                if (!empty($bodyRequired)) {
                    $body['content'][$consumes[0]]['schema']['required'] = $bodyRequired;
                }

                if (!empty($body['content'][$consumes[0]]['schema']['properties'])) {
                    $methodTemp['requestBody'] = $body;
                }

                $output['paths'][$url][\strtolower($method)] = $methodTemp;
            }
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

                $type = '';
                $format = $rule['format'] ?? null;
                $items = null;

                $examples[$name] = $rule['example'] ?? null;

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

                            if ($rule['array']) {
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
                if ($rule['type'] == 'json' && !$rule['array']) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name] = [
                        'type' => $type,
                        'additionalProperties' => true,
                        'description' => $rule['description'] ?? '',
                    ];

                    if (isset($rule['example']) && ($type === 'string' || $rule['example'] !== '')) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['example'] = $this->normalizeExample($rule['example'], $type);
                    }
                    if ($readOnly) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['readOnly'] = true;
                    }
                    continue;
                }

                if ($rule['array']) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name] = [
                        'type' => 'array',
                        'description' => $rule['description'] ?? '',
                        'items' => [
                            'type' => $type,
                        ],
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
                    ];

                    if ($format) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['format'] = $format;
                    }
                    if ($readOnly) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['readOnly'] = true;
                    }
                }

                $propertyType = $rule['array'] ? 'array' : $type;
                if (isset($rule['example']) && (\in_array($propertyType, ['string', 'array']) || $rule['example'] !== '')) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name]['example'] = $this->normalizeExample($rule['example'], $propertyType);
                }
                if ($items) {
                    if ($rule['array'] || $rule['type'] === 'array') {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['items'] = $items;
                    } else {
                        if (isset($items['$ref']) || isset($items['oneOf'])) {
                            $items = ['allOf' => [$items]];
                        }
                        /** @var array<string, mixed> $property */
                        $property = $output['components']['schemas'][$model->getType()]['properties'][$name];
                        $output['components']['schemas'][$model->getType()]['properties'][$name] = [
                            ...$property,
                            ...$items,
                        ];
                    }
                }
                if ($rule['type'] === 'enum' && !empty($rule['enum'])) {
                    $enum = $this->getEnumSchema(
                        \array_values($rule['enum']),
                        $rule['enumSDKName'] ?? null,
                        $name,
                        \array_values($rule['enum']),
                    );

                    if ($rule['array']) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['items'] = $enum;
                    } else {
                        unset($output['components']['schemas'][$model->getType()]['properties'][$name]['type']);
                        $output['components']['schemas'][$model->getType()]['properties'][$name] = [
                            ...$output['components']['schemas'][$model->getType()]['properties'][$name],
                            ...$enum,
                        ];
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

            $output['components']['schemas'][$model->getType()]['example'] = (object) $examples;
        }

        \ksort($output['paths']);

        return $output;
    }
}
