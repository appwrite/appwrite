<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\InputFile;
use Appwrite\GraphQL\Types\Json;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use Utopia\CLI\Console;
use Utopia\Database\Database;

class TypeRegistry
{
    private static array $register = [];

    /**
     * Check if a type exists in the registry.
     *
     * @param string $type
     * @return bool
     */
    public static function has(string $type): bool
    {
        return isset(self::$register[$type]);
    }

    /**
     * Get a type from the registry.
     *
     * @param string $type
     * @return Type
     */
    public static function get(string $type): Type
    {
        return self::$register[$type];
    }

    /**
     * Set a type in the registry.
     *
     * @param string $type
     * @param Type $typeObject
     */
    public static function set(string $type, Type $typeObject): void
    {
        self::$register[$type] = $typeObject;
    }
}
