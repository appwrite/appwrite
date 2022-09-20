<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\InputFile;
use Appwrite\GraphQL\Types\Json;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Utopia\CLI\Console;
use Utopia\Database\Database;

class TypeRegistry
{
    private static ?Json $jsonType = null;
    private static ?InputFile $inputFile = null;

    private static array $typeMapping = [];
    private static array $defaultDocumentArgs = [];
    private static array $models = [];

    /**
     * Initialize the type registry for the given set of models.
     *
     * @param array<Model> $models
     */
    public static function init(array $models): void
    {
        self::$typeMapping = [
            Model::TYPE_BOOLEAN => Type::boolean(),
            Model::TYPE_STRING => Type::string(),
            Model::TYPE_INTEGER => Type::int(),
            Model::TYPE_FLOAT => Type::float(),
            Model::TYPE_JSON => self::json(),
            Response::MODEL_NONE => self::json(),
            Response::MODEL_ANY => self::json(),
            Model::TYPE_DATETIME => Type::string(),
        ];
        self::$defaultDocumentArgs = [
            'id' => [
                'id' => [
                    'type' => Type::string(),
                ],
            ],
            'list' => [
                'limit' => [
                    'type' => Type::int(),
                    'defaultValue' => 25,
                ],
                'offset' => [
                    'type' => Type::int(),
                    'defaultValue' => 0,
                ],
                'cursor' => [
                    'type' => Type::string(),
                    'defaultValue' => '',
                ],
                'cursorDirection' => [
                    'type' => Type::string(),
                    'defaultValue' => Database::CURSOR_AFTER,
                ],
                'orderAttributes' => [
                    'type' => Type::listOf(Type::string()),
                    'defaultValue' => [],
                ],
                'orderTypes' => [
                    'type' => Type::listOf(Type::string()),
                    'defaultValue' => [],
                ],
            ],
            'mutate' => [
                'read' => [
                    'type' => Type::listOf(Type::string()),
                    'defaultValue' => ['role:member'],
                ],
                'write' => [
                    'type' => Type::listOf(Type::string()),
                    'defaultValue' => ['role:member'],
                ],
            ],
        ];

        self::$models = $models;
    }

    /**
     * Check if a type exists in the registry.
     *
     * @param string $type
     * @return bool
     */
    public static function has(string $type): bool
    {
        return isset(self::$typeMapping[$type]);
    }

    /**
     * Get a type from the registry, creating it if it does not already exist.
     *
     * @param string $name
     * @return Type
     */
    public static function get(string $name): Type
    {
        if (self::has($name)) {
            return self::$typeMapping[$name];
        }

        $fields = [];

        $model = self::$models[$name];

        if ($model->isAny()) {
            $fields['data'] = [
                'type' => Type::string(),
                'description' => 'Data field',
                'resolve' => static fn($object, $args, $context, $info) => \json_encode($object, JSON_FORCE_OBJECT),
            ];
        }

        foreach ($model->getRules() as $key => $props) {
            $escapedKey = str_replace('$', '_', $key);

            $types = \is_array($props['type'])
                ? $props['type']
                : [$props['type']];

            foreach ($types as $type) {
                if (self::has($type)) {
                    $type = self::$typeMapping[$type];
                } else {
                    try {
                        $complexModel = self::$models[$type];
                        $type = self::get($complexModel->getType());
                    } catch (\Exception) {
                        Console::error('Could not find model for ' . $type);
                    }
                }

                if ($props['array']) {
                    $type = Type::listOf($type);
                }

                $fields[$escapedKey] = [
                    'type' => $type,
                    'description' => $props['description'],
                    'resolve' => static fn($object, $args, $context, $info) => $object[$key],
                ];
            }
        }
        $objectType = [
            'name' => $name,
            'fields' => $fields
        ];

        self::set($name, new ObjectType($objectType));

        return self::$typeMapping[$name];
    }

    /**
     * Set a type in the registry.
     *
     * @param string $type
     * @param Type $typeObject
     */
    public static function set(string $type, Type $typeObject): void
    {
        self::$typeMapping[$type] = $typeObject;
    }

    /**
     * Get the registered default arguments for a given key.
     *
     * @param string $key
     * @return array
     */
    public static function defaultArgsFor(string $key): array
    {
        if (isset(self::$defaultDocumentArgs[$key])) {
            return self::$defaultDocumentArgs[$key];
        }
        return [];
    }

    /**
     * Get the JSON type.
     *
     * @return Json
     */
    public static function json(): Json
    {
        if (\is_null(self::$jsonType)) {
            self::$jsonType = new Json();
        }
        return self::$jsonType;
    }

    /**
     * Get the InputFile type.
     *
     * @return InputFile
     */
    public static function inputFile(): InputFile
    {
        if (\is_null(self::$inputFile)) {
            self::$inputFile = new InputFile();
        }
        return self::$inputFile;
    }
}
