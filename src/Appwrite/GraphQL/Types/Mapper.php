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
    private static array $models = [];
    private static array $args = [];
    private static array $blacklist = [
        '/v1/mock',
        '/v1/graphql',
        '/v1/account/sessions/oauth2',
    ];

    public static function init(array $models): void
    {
        self::$models = $models;

        self::$args = [
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

        foreach ($defaults as $type => $default) {
            Registry::set($type, $default);
        }
    }

    /**
     * Get the registered default arguments for a given key.
     *
     * @param string $key
     * @return array
     */
    public static function args(string $key): array
    {
        return self::$args[$key] ?? [];
    }

    public static function route(
        App $utopia,
        Route $route,
        Method $method,
        callable $complexity
    ): iterable {
        foreach (self::$blacklist as $blacklist) {
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
                        $models[] = static::$models[$name];
                    }
                } else {
                    $models[] = static::$models[$modelName];
                }
            }
        } else {
            // If single response, get its model and wrap in array
            $modelName = $responses->getModel();
            $models = [static::$models[$modelName]];
        }

        foreach ($models as $model) {
            $type = Mapper::model(\ucfirst($model->getType()));
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

                $parameterType = Mapper::param(
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
    public static function model(string $name): Type
    {
        if (Registry::has($name)) {
            return Registry::get($name);
        }

        $fields = [];
        $model = self::$models[\lcfirst($name)];

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
                $type = self::getUnionType($escapedKey, $rule);
            } else {
                $type = self::getObjectType($rule);
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

        Registry::set($name, $type);

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
    public static function param(
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
                $type = Type::listOf(self::param(
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
    public static function attribute(string $type, bool $array, bool $required): Type
    {
        if ($array) {
            return Type::listOf(self::attribute(
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

    private static function getObjectType(array $rule): Type
    {
        $type = $rule['type'];

        if (Registry::has($type)) {
            return Registry::get($type);
        }

        $complexModel = self::$models[$type];
        return self::model(\ucfirst($complexModel->getType()));
    }

    private static function getUnionType(string $name, array $rule): Type
    {
        $unionName = \ucfirst($name);

        if (Registry::has($unionName)) {
            return Registry::get($unionName);
        }

        $types = [];
        foreach ($rule['type'] as $type) {
            $types[] = self::model(\ucfirst($type));
        }

        $unionType = new UnionType([
            'name' => $unionName,
            'types' => $types,
            'resolveType' => static function ($object) use ($unionName) {
                return static::getUnionImplementation($unionName, $object);
            },
        ]);

        Registry::set($unionName, $unionType);

        return $unionType;
    }

    private static function getUnionImplementation(string $name, array $object): Type
    {
        // TODO: Find a better way to do this

        switch ($name) {
            case 'Attributes':
                return static::getColumnImplementation($object);
            case 'Columns':
                return static::getColumnImplementation($object, true);
            case 'HashOptions':
                return static::getHashOptionsImplementation($object);
        }

        throw new Exception('Unknown union type: ' . $name);
    }

    private static function getColumnImplementation(array $object, bool $isColumns = false): Type
    {
        $prefix = $isColumns ? 'Column' : 'Attribute';

        return match ($object['type']) {
            'string' => match ($object['format'] ?? '') {
                'email' => static::model("{$prefix}Email"),
                'url' => static::model("{$prefix}Url"),
                'ip' => static::model("{$prefix}Ip"),
                default => static::model("{$prefix}String"),
            },
            'enum' => static::model("{$prefix}String"), // TODO: Add enum type (breaking change if added)
            'integer' => static::model("{$prefix}Integer"),
            'double' => static::model("{$prefix}Float"),
            'boolean' => static::model("{$prefix}Boolean"),
            'datetime' => static::model("{$prefix}Datetime"),
            'relationship' => static::model("{$prefix}Relationship"),
            'point' => static::model("{$prefix}Point"),
            'linestring' => static::model("{$prefix}Line"),
            'polygon' => static::model("{$prefix}Polygon"),
            default => throw new Exception('Unknown ' . strtolower($prefix) . ' implementation'),
        };
    }

    private static function getHashOptionsImplementation(array $object): Type
    {
        switch ($object['type']) {
            case 'argon2':
                return static::model('AlgoArgon2');
            case 'bcrypt':
                return static::model('AlgoBcrypt');
            case 'md5':
                return static::model('AlgoMd5');
            case 'phpass':
                return static::model('AlgoPhpass');
            case 'scrypt':
                return static::model('AlgoScrypt');
            case 'scryptMod':
                return static::model('AlgoScryptModified');
            case 'sha':
                return static::model('AlgoSha');
        }

        throw new Exception('Unknown hash options implementation');
    }
}
