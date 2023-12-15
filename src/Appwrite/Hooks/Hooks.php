<?php

namespace Appwrite\Hooks;

class Hooks
{
    /**
     * @var callable[] $hooks
     */
    static array $hooks = [];

    public static function add(string $name, callable $action) {
        self::$hooks[$name] = $action;
    }

    /**
     * @param mixed[] $params
     */
    public function trigger(string $name, array $params = []) {
        \var_dump(self::$hooks);
        \var_dump(self::$hooks[$name]);
        if (isset(self::$hooks[$name])) {
            call_user_func_array(self::$hooks[$name], $params);
        }
    } 
}
