<?php

namespace Appwrite\Config;

use Appwrite\Config\Exception\Load;
use Appwrite\Config\Exception\Parse;

class Config
{
    /**
     * @var array<string, mixed>
     */
    public static array $params = [];

    /**
     * @throws Load
     * @throws Parse
     */
    public static function load(string $key, string $path, Adapter $adapter): void
    {
        if (! \is_readable($path)) {
            throw new Load('Failed to load configuration file: ' . $path);
        }

        self::$params[$key] = $adapter->load($path);
    }

    public static function setParam(string $key, mixed $value): void
    {
        self::$params[$key] = $value;
    }

    /**
     * Resolves dotted keys through nested arrays: `getParam('platform.hostname')`.
     */
    public static function getParam(string $key, mixed $default = null): mixed
    {
        if (! \str_contains($key, '.')) {
            return self::$params[$key] ?? $default;
        }

        $node = self::$params;
        foreach (\explode('.', $key) as $segment) {
            if (! \is_array($node) || ! isset($node[$segment])) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }
}
