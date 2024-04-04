<?php

namespace Appwrite\Hooks;

class Hooks
{
    /**
     * @var callable[] $hooks
     */
    private static array $hooks = [];

    public static function add(string $name, callable $action)
    {
        self::$hooks[$name] = $action;
    }

    /**
     * @param mixed[] $params
     * @return mixed
     */
    public function trigger(string $name, array $params = []): mixed
    {
        if (isset(self::$hooks[$name])) {
            return call_user_func_array(self::$hooks[$name], $params);
        }

        return null;
    }
}
