<?php

namespace Services;

class Services
{
    /**
     * @var array
     */
    static protected $list = [];

    /**
     * @var string
     */
    static protected $default = null;

    /**
     * Set a new service
     *
     * @param string $path
     * @param string $name
     * @param string $description
     * @param string $controller
     * @param bool $sdk
     */
    static public function set($path, $name, $description, $controller, $sdk = false)
    {
        self::$list[$path] = [
            'name' => $name,
            'description' => $description,
            'controller' => $controller,
            'sdk' => $sdk,
        ];
    }

    /**
     * Get service by path
     *
     * @param $path
     * @return mixed
     * @throws \Exception
     */
    static public function get($path)
    {
        if(!array_key_exists($path, self::$list)) {
            if(self::$default && array_key_exists(self::$default, self::$list)) {
                return self::$list[self::$default];
            }

            return null;
        }

        return self::$list[$path];
    }

    /**
     * Returns all services list
     *
     * @return array
     */
    static public function getAll()
    {
        return self::$list;
    }

    /**
     * Set default service
     *
     * @param $path
     */
    static public function default($path) {
        self::$default = $path;
    }
}