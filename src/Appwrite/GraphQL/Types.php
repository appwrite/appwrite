<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\Assoc;
use Appwrite\GraphQL\Types\InputFile;
use Appwrite\GraphQL\Types\Json;
use Appwrite\GraphQL\Types\Registry;
use GraphQL\Type\Definition\Type;

class Types
{
    /**
     * Get the JSON type.
     *
     * @return Json
     */
    public static function json(): Json
    {
        if (Registry::has(Json::class)) {
            $type = Registry::get(Json::class);
            if ($type instanceof Json) {
                return $type;
            }
        }
        $type = new Json();
        Registry::set(Json::class, $type);
        return $type;
    }

    /**
     * Get the JSON type.
     *
     * @return Assoc
     */
    public static function assoc(): Assoc
    {
        if (Registry::has(Assoc::class)) {
            $type = Registry::get(Assoc::class);
            if ($type instanceof Assoc) {
                return $type;
            }
        }
        $type = new Assoc();
        Registry::set(Assoc::class, $type);
        return $type;
    }

    /**
     * Get the InputFile type.
     *
     * @return InputFile
     */
    public static function inputFile(): InputFile
    {
        if (Registry::has(InputFile::class)) {
            $type = Registry::get(InputFile::class);
            if ($type instanceof InputFile) {
                return $type;
            }
        }
        $type = new InputFile();
        Registry::set(InputFile::class, $type);
        return $type;
    }
}
