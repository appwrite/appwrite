<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\Assoc;
use Appwrite\GraphQL\Types\InputFile;
use Appwrite\GraphQL\Types\Json;
use GraphQL\Type\Definition\Type;
use Swoole\Lock;

class Types
{
    private static ?Json $json = null;
    private static ?Assoc $assoc = null;
    private static ?InputFile $inputFile = null;
    private static ?Lock $lock = null;

    /**
     * Get or create the shared lock for thread-safe initialization.
     */
    private static function getLock(): Lock
    {
        if (self::$lock === null) {
            self::$lock = new Lock(SWOOLE_MUTEX);
        }
        return self::$lock;
    }

    /**
     * Get the JSON type (thread-safe).
     */
    public static function json(): Type
    {
        if (self::$json === null) {
            self::getLock()->lock();
            try {
                // Double-check after acquiring lock
                if (self::$json === null) {
                    self::$json = new Json();
                }
            } finally {
                self::getLock()->unlock();
            }
        }
        return self::$json;
    }

    /**
     * Get the Assoc type (thread-safe).
     */
    public static function assoc(): Type
    {
        if (self::$assoc === null) {
            self::getLock()->lock();
            try {
                // Double-check after acquiring lock
                if (self::$assoc === null) {
                    self::$assoc = new Assoc();
                }
            } finally {
                self::getLock()->unlock();
            }
        }
        return self::$assoc;
    }

    /**
     * Get the InputFile type (thread-safe).
     */
    public static function inputFile(): Type
    {
        if (self::$inputFile === null) {
            self::getLock()->lock();
            try {
                // Double-check after acquiring lock
                if (self::$inputFile === null) {
                    self::$inputFile = new InputFile();
                }
            } finally {
                self::getLock()->unlock();
            }
        }
        return self::$inputFile;
    }
}
