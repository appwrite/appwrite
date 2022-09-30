<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\InputFile;
use Appwrite\GraphQL\Types\Json;
use GraphQL\Type\Definition\Type;

class Types
{
    /**
     * Get the JSON type.
     *
     * @return Json
     */
    public static function json(): Type
    {
        if (TypeRegistry::has(Json::class)) {
            return TypeRegistry::get(Json::class);
        }
        $type = new Json();
        TypeRegistry::set(Json::class, $type);
        return $type;
    }

    /**
     * Get the InputFile type.
     *
     * @return InputFile
     */
    public static function inputFile(): Type
    {
        if (TypeRegistry::has(InputFile::class)) {
            return TypeRegistry::get(InputFile::class);
        }
        $type = new InputFile();
        TypeRegistry::set(InputFile::class, $type);
        return $type;
    }
}
