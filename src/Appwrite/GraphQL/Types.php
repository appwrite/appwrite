<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\Assoc;
use Appwrite\GraphQL\Types\InputFile;
use Appwrite\GraphQL\Types\Json;
use GraphQL\Type\Definition\Type;

class Types
{
    private static ?Json $json = null;
    private static ?Assoc $assoc = null;
    private static ?InputFile $inputFile = null;

    /**
     * Get the JSON type.
     *
     * Thread-safety note: In Swoole, each worker is a separate process with its own
     * static variables. Within a worker, coroutines are cooperative and only yield
     * at I/O points. Since these constructors have no I/O, the null check and
     * assignment execute atomically without needing locks.
     */
    public static function json(): Type
    {
        if (self::$json === null) {
            self::$json = new Json();
        }
        return self::$json;
    }

    /**
     * Get the Assoc type.
     */
    public static function assoc(): Type
    {
        if (self::$assoc === null) {
            self::$assoc = new Assoc();
        }
        return self::$assoc;
    }

    /**
     * Get the InputFile type.
     */
    public static function inputFile(): Type
    {
        if (self::$inputFile === null) {
            self::$inputFile = new InputFile();
        }
        return self::$inputFile;
    }
}
