<?php

/**
 * Area `objects`: any class of the library outside Database and its
 * adapters (Document, Query, Operator, Attribute, Index, Relationship,
 * Collection, the validators, Permission, Role, Id, DateTime, Authorization,
 * Filter\Registry, Cache\Query, the events, ...), driven by reflection the
 * way `database.<method>` drives Database. Twin:
 * crates/tools/compat/src/libs/database/objects.rs.
 *
 *   object.new   {class, args?, as?}                 new <class>(...args)
 *   object.call  {class? | this, method, args?, as?} <class>::method(...args), or
 *                                                     <this>->method(...args)
 *   object.get   {this, property}                     <this>->property
 *
 * `class` is relative to Utopia\Database (`Document`, `Validator\Structure`),
 * or to Utopia with a `Query\` prefix (`Query\Schema\ColumnType`). `args`
 * are the parameters by name, decoded for each parameter's type as
 * `database.*` decodes them (Core::argument). `this` is `{"$obj": <name>}`,
 * an object an earlier step stored with `as` (the same instance, so the
 * setters' changes stay), or a value tag decoded afresh. `{"$obj": <name>}`
 * anywhere in `args` is that stored object. With `as`, the step stores what
 * it built or returned under the name. The result is the encoded value
 * (Core::result): `object.new` returns the object it built.
 */

namespace Tests\Compat\Database;

use Tests\Compat\Session;

require_once \dirname(__DIR__) . '/Objects.php';

return Core::operations([
    'object.new' => static fn (array $a, Session $s) => Objects::make(Objects::class($a['class'] ?? null), (array) ($a['args'] ?? []), $s),
    'object.call' => static function (array $a, Session $s) {
        $method = (string) ($a['method'] ?? '');
        $args = Objects::resolve((array) ($a['args'] ?? []), $s);
        $target = \array_key_exists('this', $a) ? Objects::target($a['this'], $s) : Objects::class($a['class'] ?? null);

        return Core::invoke($target, $method, $args, $s);
    },
    'object.get' => static function (array $a, Session $s) {
        $target = Objects::target($a['this'] ?? null, $s);
        $property = (string) ($a['property'] ?? '');
        $reflection = new \ReflectionObject($target);
        if (!$reflection->hasProperty($property) || !$reflection->getProperty($property)->isPublic()) {
            throw new \Tests\Compat\Fault($target::class . " has no public property `{$property}`");
        }

        return $target->{$property};
    },
]);
