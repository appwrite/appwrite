<?php

namespace Appwrite\GraphQL\Types;

use Appwrite\GraphQL\Resolvers;
use Appwrite\GraphQL\Types;
use Appwrite\SDK\Method;
use Exception;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use Utopia\App;
use Utopia\Route;
use Utopia\Validator;
use Utopia\Validator\Nullable;

class Mapper
{
    private Registry $registry;
    private array $models;
    private array $args;
    private array $blacklist = [
        '/v1/mock',
        '/v1/graphql',
        '/v1/account/sessions/oauth2',
    ];

    /**
     * Create a new Mapper instance.
     *
     * @param Registry $registry The type registry instance
     * @param array $models The response models
     */
    public function __construct(Registry $registry, array $models)
    {
        $this->registry = $registry;
        $this->models = $models;

        $this->args = [
            'id' => [
                'id' => [
                    'type' => Type::nonNull(Type::string()),
                ],
            ],
            'list' => [
                'queries' => [
                    'type' => Type::listOf(Type::nonNull(Type::string())),
                    'defaultValue' => [],
                ],
            ],
            'mutate' => [
                'id' => [
                    'type' => Type::string(),
                    'defaultValue' => null,
                ],
                'permissions' => [
                    'type' => Type::listOf(Type::nonNull(Type::string())),
                    'defaultValue' => [],
                ]
            ],
        ];

        $defaults = [
            'boolean' => Type::boolean(),
            'string' => Type::string(),
            'payload' => Type::string(),
            'integer' => Type::int(),
            'double' => Type::float(),
            'datetime' => Type::string(),
            'json' => Types::json(),
            'none' => Types::json(),
            'any' => Types::json(),
            'array' => Types::json(),
            'enum' => Type::string()
        ];

        $this->registry->initBaseTypes($defaults);
    }

    /**
     * Get the registered default arguments for a given key.
     *
     * @param string $key
     * @return array
     */
    public function args(string $key): array
    {
        return $this->args[$key] ?? [];
    }

    /**
     * Map a route to GraphQL fields.
     *
     * @param App $utopia
     * @param Route $route
     * @param Method $method
     * @param callable $complexity
     * @return iterable<array> Iterator of GraphQL field definitions
     */
    public function route(
        App $utopia,
        Route $route,
        Method $method,
        callable $complexity
    ): iterable {
        foreach ($this->blacklist as $blacklist) {
            if (\str_starts_with($route->getPath(), $blacklist)) {
                return;
            }
        }

        $responses = $method->getResponses() ?? [];

        // If responses is an array, map each response to its model
        if (\is_array($responses)) {
            $models = [];
            foreach ($responses as $response) {
                $modelName = $response->getModel();

                if (\is_array($modelName)) {
                    foreach ($modelName as $name) {
                        $models[] = $this->models[$name];
                    }
                } else {
                    $models[] = $this->models[$modelName];
                }
            }
        } else {
            // If single response, get its model and wrap in array
            $modelName = $responses->getModel();
            $models = [$this->models[$modelName]];
        }

        foreach ($models as $model) {
            $type = $this->model(\ucfirst($model->getType()));
            $description = $route->getDesc();
            $params = [];
            $list = false;

            foreach ($route->getParams() as $name => $parameter) {
                $sdkParameters = $method->getParameters();

                if (!empty($sdkParameters)) {
                    $sdkMethodParameters = [];
                    foreach ($sdkParameters as $sdkParameter) {
                        $sdkMethodParameters[$sdkParameter->getName()] = $sdkParameter;
                    }

                    if (!\array_key_exists($name, $sdkMethodParameters)) {
                        continue;
                    }

                    $optional = $sdkMethodParameters[$name]->getOptional();
                } else {
                    $optional = $parameter['optional'];
                }

                if ($name === 'queries') {
                    $list = true;
                }

                $parameterType = $this->param(
                    $utopia,
                    $parameter['validator'],
                    !$optional,
                    $parameter['injections']
                );
                $params[$name] = [
                    'type' => $parameterType,
                    'description' => $parameter['description'],
                ];
            }

            $field = [
                'type' => $type,
                'description' => $description,
                'args' => $params,
                'resolve' => Resolvers::api($utopia, $route)
            ];

            if ($list) {
                $field['complexity'] = $complexity;
            }

            yield $field;
        }
    }

    /**
     * Get a type from the registry, creating it if it does not already exist.
     *
     * @param string $name
     * @return Type
     */
    public function model(string $name): Type
    {
        if ($this->registry->has($name)) {
            return $this->registry->get($name);
        }

        $fields = [];
        $model = $this->models[\lcfirst($name)];

        // If model has additional properties, explicitly add a 'data' field
        if ($model->isAny()) {
            $fields['data'] = [
                'type' => Type::string(),
                'description' => 'Additional data',
                'resolve' => static function ($object, $args, $context, $info) {
                    $data = \array_filter(
                        (array)$object,
                        fn ($key) => !\str_starts_with($key, '_'),
                        ARRAY_FILTER_USE_KEY
                    );

                    return \json_encode($data, JSON_FORCE_OBJECT);
                }
            ];
        }

        // If model has no properties, explicitly add a 'status' field
        // because GraphQL requires at least 1 field per type.
        if (!$model->isAny() && empty($model->getRules())) {
            $fields['status'] = [
                'type' => Type::string(),
                'description' => 'Status',
                'resolve' => static fn ($object, $args, $context, $info) => 'OK',
            ];
        }

        foreach ($model->getRules() as $key => $rule) {
            $escapedKey = str_replace('$', '_', $key);

            if (\is_array($rule['type'])) {
                $type = $this->getUnionType($escapedKey, $rule);
            } else {
                $type = $this->getObjectType($rule);
            }

            if ($rule['array']) {
                $type = Type::listOf($type);
            }

            $fields[$escapedKey] = [
                'type' => $type,
                'description' => $rule['description'],
            ];

            if (!$rule['required']) {
                $fields[$escapedKey]['defaultValue'] = $rule['default'] ?? null;
            }
        }

        $type = new ObjectType([
            'name' => $name,
            'fields' => $fields,
        ]);

        $this->registry->set($name, $type);

        return $type;
    }

    /**
     * Map a {@see Route} parameter to a GraphQL Type
     *
     * @param App $utopia
     * @param Validator|callable $validator
     * @param bool $required
     * @param array $injections
     * @return Type
     * @throws Exception
     */
    public function param(
        App $utopia,
        Validator|callable $validator,
        bool $required,
        array $injections
    ): Type {
        $validator = \is_callable($validator)
            ? \call_user_func_array($validator, $utopia->getResources($injections))
            : $validator;

        $isNullable = $validator instanceof Nullable;

        if ($isNullable) {
            $validator = $validator->getValidator();
        }

        switch ((!empty($validator)) ? $validator::class : '') {
            case 'Appwrite\Auth\Validator\Password':
            case 'Appwrite\Event\Validator\Event':
            case 'Appwrite\Event\Validator\FunctionEvent':
            case 'Appwrite\Network\Validator\CNAME':
            case 'Appwrite\Network\Validator\Email':
            case 'Appwrite\Network\Validator\Redirect':
            case 'Appwrite\Network\Validator\DNS':
            case 'Appwrite\Network\Validator\Origin':
            case 'Appwrite\Task\Validator\Cron':
            case 'Appwrite\Utopia\Database\Validator\CustomId':
            case 'Utopia\Database\Validator\Key':
            case 'Utopia\Database\Validator\UID':
            case 'Utopia\Validator\Domain':
            case 'Utopia\Validator\HexColor':
            case 'Utopia\Validator\Host':
            case 'Utopia\Validator\IP':
            case 'Utopia\Validator\Origin':
            case 'Utopia\Validator\Text':
            case 'Utopia\Validator\URL':
            case 'Utopia\Validator\WhiteList':
            default:
                $type = Type::string();
                break;
            case 'Appwrite\Utopia\Database\Validator\Queries\Attributes':
            case 'Appwrite\Utopia\Database\Validator\Queries\Base':
            case 'Appwrite\Utopia\Database\Validator\Queries\Buckets':
            case 'Appwrite\Utopia\Database\Validator\Queries\Tables':
            case 'Appwrite\Utopia\Database\Validator\Queries\Collections':
            case 'Appwrite\Utopia\Database\Validator\Queries\Columns':
            case 'Appwrite\Utopia\Database\Validator\Queries\Databases':
            case 'Appwrite\Utopia\Database\Validator\Queries\Deployments':
            case 'Appwrite\Utopia\Database\Validator\Queries\Executions':
            case 'Appwrite\Utopia\Database\Validator\Queries\Files':
            case 'Appwrite\Utopia\Database\Validator\Queries\Functions':
            case 'Appwrite\Utopia\Database\Validator\Queries\Indexes':
            case 'Appwrite\Utopia\Database\Validator\Queries\Installations':
            case 'Appwrite\Utopia\Database\Validator\Queries\Memberships':
            case 'Appwrite\Utopia\Database\Validator\Queries\Projects':
            case 'Appwrite\Utopia\Database\Validator\Queries\Rules':
            case 'Appwrite\Utopia\Database\Validator\Queries\Teams':
            case 'Appwrite\Utopia\Database\Validator\Queries\Users':
            case 'Appwrite\Utopia\Database\Validator\Queries\Variables':
            case 'Utopia\Database\Validator\Authorization':
            case 'Utopia\Database\Validator\Permissions':
            case 'Utopia\Database\Validator\Queries':
            case 'Utopia\Database\Validator\Queries\Documents':
            case 'Utopia\Database\Validator\Roles':
                $type = Type::listOf(Type::string());
                break;
            case 'Utopia\Validator\Boolean':
                $type = Type::boolean();
                break;
            case 'Utopia\Validator\ArrayList':
                $type = Type::listOf($this->param(
                    $utopia,
                    $validator->getValidator(),
                    $required,
                    $injections
                ));
                break;
            case 'Utopia\Validator\Integer':
            case 'Utopia\Validator\Numeric':
                $type = Type::int();
                break;
            case 'Utopia\Validator\Range':
                // Check if the Range validator is for float or integer
                if ($validator instanceof \Utopia\Validator\Range && $validator->getType() === \Utopia\Validator\Range::TYPE_FLOAT) {
                    $type = Type::float();
                } else {
                    $type = Type::int();
                }
                break;
            case 'Utopia\Validator\FloatValidator':
                $type = Type::float();
                break;
            case 'Utopia\Validator\Assoc':
                $type = Types::assoc();
                break;
            case 'Utopia\Validator\JSON':
                $type = Types::json();
                break;
            case 'Utopia\Storage\Validator\File':
                $type = Types::inputFile();
                break;
        }

        if ($required && !$isNullable) {
            $type = Type::nonNull($type);
        }

        return $type;
    }

    /**
     * Map an {@see Attribute} to a GraphQL Type
     *
     * @param string $type
     * @param bool $array
     * @param bool $required
     * @return Type
     * @throws Exception
     */
    public function attribute(string $type, bool $array, bool $required): Type
    {
        if ($array) {
            return Type::listOf($this->attribute(
                $type,
                false,
                $required
            ));
        }

        $type = match ($type) {
            'boolean' => Type::boolean(),
            'integer' => Type::int(),
            'double' => Type::float(),
            default => Type::string(),
        };

        if ($required) {
            $type = Type::nonNull($type);
        }

        return $type;
    }

    private function getObjectType(array $rule): Type
    {
        $type = $rule['type'];

        if ($this->registry->has($type)) {
            return $this->registry->get($type);
        }

        $complexModel = $this->models[$type];
        return $this->model(\ucfirst($complexModel->getType()));
    }

    private function getUnionType(string $name, array $rule): Type
    {
        $unionName = \ucfirst($name);

        if ($this->registry->has($unionName)) {
            return $this->registry->get($unionName);
        }

        $types = [];
        foreach ($rule['type'] as $type) {
            $types[] = $this->model(\ucfirst($type));
        }

        // resolveType returns a string type name instead of a Type object.
        // This ensures GraphQL looks up the type from the schema's type map,
        // which is essential for cached schemas where the original type instances
        // must be used (not newly created ones from calling model()).
        $unionType = new UnionType([
            'name' => $unionName,
            'types' => $types,
            'resolveType' => static function ($object) use ($unionName) {
                return self::getUnionTypeName($unionName, $object);
            },
        ]);

        $this->registry->set($unionName, $unionType);

        return $unionType;
    }

    /**
     * Get the type name for a union member based on the object data.
     * Returns a string type name that GraphQL will look up in the schema.
     *
     * @param string $name The union type name
     * @param array $object The object data
     * @return string The type name
     * @throws Exception
     */
    public static function getUnionTypeName(string $name, array $object): string
    {
        return match ($name) {
            'Attributes' => self::getColumnTypeName($object),
            'Columns' => self::getColumnTypeName($object, true),
            'HashOptions' => self::getHashOptionsTypeName($object),
            default => throw new Exception('Unknown union type: ' . $name),
        };
    }

    private static function getColumnTypeName(array $object, bool $isColumns = false): string
    {
        $prefix = $isColumns ? 'Column' : 'Attribute';

        return match ($object['type']) {
            'string' => match ($object['format'] ?? '') {
                'email' => "{$prefix}Email",
                'url' => "{$prefix}Url",
                'ip' => "{$prefix}Ip",
                default => "{$prefix}String",
            },
            'enum' => "{$prefix}String", // TODO: Add enum type (breaking change if added)
            'integer' => "{$prefix}Integer",
            'double' => "{$prefix}Float",
            'boolean' => "{$prefix}Boolean",
            'datetime' => "{$prefix}Datetime",
            'relationship' => "{$prefix}Relationship",
            'point' => "{$prefix}Point",
            'linestring' => "{$prefix}Line",
            'polygon' => "{$prefix}Polygon",
            default => throw new Exception('Unknown ' . strtolower($prefix) . ' implementation'),
        };
    }

    private static function getHashOptionsTypeName(array $object): string
    {
        return match ($object['type']) {
            'argon2' => 'AlgoArgon2',
            'bcrypt' => 'AlgoBcrypt',
            'md5' => 'AlgoMd5',
            'phpass' => 'AlgoPhpass',
            'scrypt' => 'AlgoScrypt',
            'scryptMod' => 'AlgoScryptModified',
            'sha' => 'AlgoSha',
            default => throw new Exception('Unknown hash options implementation'),
        };
    }
}
