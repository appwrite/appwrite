<?php

namespace Appwrite\GraphQL\Types;

use Appwrite\GraphQL\Resolvers;
use Appwrite\GraphQL\Types;
use Exception;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use Utopia\App;
use Utopia\Route;
use Utopia\Validator;

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
            'integer' => Type::int(),
            'double' => Type::float(),
            'datetime' => Type::string(),
            'json' => Types::json(),
            'none' => Types::json(),
            'any' => Types::json(),
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
        callable $complexity
    ): iterable {
        foreach (self::$blacklist as $blacklist) {
            if (\str_starts_with($route->getPath(), $blacklist)) {
                return;
            }
        }

        $names = $route->getLabel('sdk.response.model', 'none');
        $models = \is_array($names)
            ? \array_map(static fn($m) => static::$models[$m], $names)
            : [static::$models[$names]];

        foreach ($models as $model) {
            $type = Mapper::model(\ucfirst($model->getType()));
            $description = $route->getDesc();
            $params = [];
            $list = false;

            foreach ($route->getParams() as $name => $parameter) {
                if ($name === 'queries') {
                    $list = true;
                }
                $parameterType = Mapper::param(
                    $utopia,
                    $parameter['validator'],
                    !$parameter['optional'],
                    $parameter['injections']
                );
                $params[$name] = [
                    'type' => $parameterType,
                    'description' => $parameter['description'],
                ];
                if ($parameter['optional']) {
                    $params[$name]['defaultValue'] = $parameter['default'];
                }
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
                        fn($key) => !\str_starts_with($key, '_'),
                        ARRAY_FILTER_USE_KEY
                    );

                    return \json_encode($data, JSON_FORCE_OBJECT);
                }
            ];
        }

        // If model has no properties, explicitly add a 'status' field because GraphQL requires at least 1 field per type.
        if (!$model->isAny() && empty($model->getRules())) {
            $fields['status'] = [
                'type' => Type::string(),
                'description' => 'Status',
                'resolve' => static fn($object, $args, $context, $info) => 'OK',
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
                $fields[$escapedKey]['defaultValue'] = $rule['default'];
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

        switch ((!empty($validator)) ? $validator::class : '') {
            case 'Appwrite\Network\Validator\CNAME':
            case 'Appwrite\Task\Validator\Cron':
            case 'Appwrite\Utopia\Database\Validator\CustomId':
            case 'Appwrite\Network\Validator\Domain':
            case 'Appwrite\Network\Validator\Email':
            case 'Appwrite\Event\Validator\Event':
            case 'Utopia\Validator\HexColor':
            case 'Appwrite\Network\Validator\Host':
            case 'Appwrite\Network\Validator\IP':
            case 'Utopia\Database\Validator\Key':
            case 'Appwrite\Network\Validator\Origin':
            case 'Appwrite\Auth\Validator\Password':
            case 'Utopia\Validator\Text':
            case 'Utopia\Database\Validator\UID':
            case 'Appwrite\Network\Validator\URL':
            case 'Utopia\Validator\WhiteList':
            default:
                $type = Type::string();
                break;
            case 'Utopia\Database\Validator\Authorization':
            case 'Appwrite\Utopia\Database\Validator\Queries\Base':
            case 'Appwrite\Utopia\Database\Validator\Queries\Buckets':
            case 'Appwrite\Utopia\Database\Validator\Queries\Collections':
            case 'Appwrite\Utopia\Database\Validator\Queries\Databases':
            case 'Appwrite\Utopia\Database\Validator\Queries\Deployments':
            case 'Appwrite\Utopia\Database\Validator\Queries\Documents':
            case 'Appwrite\Utopia\Database\Validator\Queries\Executions':
            case 'Appwrite\Utopia\Database\Validator\Queries\Files':
            case 'Appwrite\Utopia\Database\Validator\Queries\Functions':
            case 'Appwrite\Utopia\Database\Validator\Queries\Memberships':
            case 'Utopia\Database\Validator\Permissions':
            case 'Appwrite\Utopia\Database\Validator\Queries\Projects':
            case 'Appwrite\Utopia\Database\Validator\Queries':
            case 'Utopia\Database\Validator\Roles':
            case 'Appwrite\Utopia\Database\Validator\Queries\Teams':
            case 'Appwrite\Utopia\Database\Validator\Queries\Users':
            case 'Appwrite\Utopia\Database\Validator\Queries\Variables':
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
            case 'Utopia\Validator\Range':
                $type = Type::int();
                break;
            case 'Utopia\Validator\FloatValidator':
                $type = Type::float();
                break;
            case 'Utopia\Validator\Assoc':
            case 'Utopia\Validator\JSON':
                $type = Types::json();
                break;
            case 'Utopia\Storage\Validator\File':
                $type = Types::inputFile();
                break;
        }

        if ($required) {
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
                return static::getAttributeImplementation($object);
            case 'HashOptions':
                return static::getHashOptionsImplementation($object);
        }

        throw new Exception('Unknown union type: ' . $name);
    }

    private static function getAttributeImplementation(array $object): Type
    {
        switch ($object['type']) {
            case 'string':
                return match ($object['format'] ?? '') {
                    'email' => static::model('AttributeEmail'),
                    'url' => static::model('AttributeUrl'),
                    'ip' => static::model('AttributeIp'),
                    default => static::model('AttributeString'),
                };
            case 'integer':
                return static::model('AttributeInteger');
            case 'double':
                return static::model('AttributeFloat');
            case 'boolean':
                return static::model('AttributeBoolean');
            case 'datetime':
                return static::model('AttributeDatetime');
        }

        throw new Exception('Unknown attribute implementation');
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
