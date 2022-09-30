<?php

namespace Appwrite\GraphQL;

use Appwrite\Auth\Validator\Password;
use Appwrite\Event\Validator\Event;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Network\Validator\Domain;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Host;
use Appwrite\Network\Validator\IP;
use Appwrite\Network\Validator\Origin;
use Appwrite\Network\Validator\URL;
use Appwrite\Task\Validator\Cron;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries;
use Appwrite\Utopia\Database\Validator\Queries\Base;
use Appwrite\Utopia\Database\Validator\Queries\Buckets;
use Appwrite\Utopia\Database\Validator\Queries\Collections;
use Appwrite\Utopia\Database\Validator\Queries\Databases;
use Appwrite\Utopia\Database\Validator\Queries\Deployments;
use Appwrite\Utopia\Database\Validator\Queries\Documents;
use Appwrite\Utopia\Database\Validator\Queries\Executions;
use Appwrite\Utopia\Database\Validator\Queries\Files;
use Appwrite\Utopia\Database\Validator\Queries\Functions;
use Appwrite\Utopia\Database\Validator\Queries\Memberships;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Database\Validator\Queries\Teams;
use Appwrite\Utopia\Database\Validator\Queries\Users;
use Appwrite\Utopia\Database\Validator\Queries\Variables;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\Attribute;
use Exception;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\UID;
use Utopia\Route;
use Utopia\Storage\Validator\File;
use Utopia\Validator;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
use Utopia\Validator\JSON;
use Utopia\Validator\Numeric;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class TypeMapper
{
    private static array $models = [];
    private static array $defaultArgs = [];

    public static function init(array $models): void
    {
        self::$models = $models;

        self::$defaultArgs = [
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

        $defaultTypes = [
            Model::TYPE_BOOLEAN => Type::boolean(),
            Model::TYPE_STRING => Type::string(),
            Model::TYPE_INTEGER => Type::int(),
            Model::TYPE_FLOAT => Type::float(),
            Model::TYPE_DATETIME => Type::string(),
            Model::TYPE_JSON => Types::json(),
            Response::MODEL_NONE => Types::json(),
            Response::MODEL_ANY => Types::json(),
        ];

        foreach ($defaultTypes as $type => $default) {
            TypeRegistry::set($type, $default);
        }
    }

    /**
     * Get the registered default arguments for a given key.
     *
     * @param string $key
     * @return array
     */
    public static function argumentsFor(string $key): array
    {
        if (isset(self::$defaultArgs[$key])) {
            return self::$defaultArgs[$key];
        }
        return [];
    }

    public static function fromRoute(App $utopia, Route $route): iterable
    {
        if (\str_starts_with($route->getPath(), '/v1/mock/')) {
            return;
        }
        if ($route->getLabel('sdk.methodType', '') === 'webAuth') {
            return;
        }

        $modelNames = $route->getLabel('sdk.response.model', 'none');
        $models = \is_array($modelNames)
            ? \array_map(static fn($m) => static::$models[$m], $modelNames)
            : [static::$models[$modelNames]];

        foreach ($models as $model) {
//            if (empty($responseModel->getRules())) {
//                \var_dump('No rules: ' . $responseModel->getType());
//                continue;
//            }

            $type = TypeMapper::fromResponseModel(\ucfirst($model->getType()));
            $description = $route->getDesc();
            $params = [];
            $list = false;

            foreach ($route->getParams() as $name => $parameter) {
                if ($name === 'queries') {
                    $list = true;
                }
                $parameterType = TypeMapper::fromRouteParameter(
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
                'resolve' => Resolvers::resolveAPIRequest($utopia, $route)
            ];

            if ($list) {
                $field['complexity'] = function (int $complexity, array $args) {
                    $queries = Query::parseQueries($args['queries'] ?? []);
                    $query = Query::getByType($queries, Query::TYPE_LIMIT)[0] ?? null;
                    $limit = $query ? $query->getValue() : APP_LIMIT_LIST_DEFAULT;

                    return $complexity * $limit;
                };
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
    public static function fromResponseModel(string $name): Type
    {
        if (TypeRegistry::has($name)) {
            return TypeRegistry::get($name);
        }

        $fields = [];
        $model = self::$models[\lcfirst($name)];

        // If model has additional properties, explicitly add a 'data' field
        if ($model->isAny()) {
            $fields['data'] = [
                'type' => Type::string(),
                'description' => 'Additional data',
                'resolve' => static fn($object, $args, $context, $info) => \json_encode($object, JSON_FORCE_OBJECT),
            ];
        }

        foreach ($model->getRules() as $key => $rule) {
            $escapedKey = str_replace('$', '_', $key);

                $type = self::getObjectType($rule);

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

        TypeRegistry::set($name, $type);

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
    public static function fromRouteParameter(
        App $utopia,
        Validator|callable $validator,
        bool $required,
        array $injections
    ): Type {
        $validator = \is_callable($validator)
            ? \call_user_func_array($validator, $utopia->getResources($injections))
            : $validator;

        switch ((!empty($validator)) ? $validator::class : '') {
            case CNAME::class:
            case Cron::class:
            case CustomId::class:
            case Domain::class:
            case Email::class:
            case Event::class:
            case HexColor::class:
            case Host::class:
            case IP::class:
            case Key::class:
            case Origin::class:
            case Password::class:
            case Text::class:
            case UID::class:
            case URL::class:
            case WhiteList::class:
            default:
                $type = Type::string();
                break;
            case Authorization::class:
            case Base::class:
            case Buckets::class:
            case Collections::class:
            case Databases::class:
            case Deployments::class:
            case Documents::class:
            case Executions::class:
            case Files::class:
            case Functions::class:
            case Memberships::class:
            case Permissions::class:
            case Projects::class:
            case Queries::class:
            case Roles::class:
            case Teams::class:
            case Users::class:
            case Variables::class:
                $type = Type::listOf(Type::string());
                break;
            case Boolean::class:
                $type = Type::boolean();
                break;
            case ArrayList::class:
                /** @noinspection PhpPossiblePolymorphicInvocationInspection */
                $type = Type::listOf(self::fromRouteParameter(
                    $utopia,
                    $validator->getValidator(),
                    $required,
                    $injections
                ));
                break;
            case Integer::class:
            case Numeric::class:
            case Range::class:
                $type = Type::int();
                break;
            case FloatValidator::class:
                $type = Type::float();
                break;
            case Assoc::class:
            case JSON::class:
                $type = Types::json();
                break;
            case File::class:
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
    public static function fromCollectionAttribute(string $type, bool $array, bool $required): Type
    {
        if ($array) {
            return Type::listOf(self::fromCollectionAttribute($type, false, $required));
        }

        $type = match ($type) {
            Database::VAR_BOOLEAN => Type::boolean(),
            Database::VAR_INTEGER => Type::int(),
            Database::VAR_FLOAT => Type::float(),
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

        if (TypeRegistry::has($type)) {
            return TypeRegistry::get($type);
        }

        $complexModel = self::$models[$type];
        return self::fromResponseModel(\ucfirst($complexModel->getType()));
    }

}
