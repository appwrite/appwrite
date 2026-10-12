<?php

/**
 * mongo.*: the MongoDB adapter (Utopia\Database\Adapter\Mongo over
 * utopia-php/mongo), on a database `db.open` opened with `engine: "mongo"`.
 * Twin: crates/tools/compat/src/libs/database/mongo.rs.
 */

use Tests\Compat\Database\Core;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Validator\Authorization;

return [
    // $db->getAdapter()->{method}(...args) on a MongoDB database: as
    // db.adapter, for the methods only the Mongo adapter has.
    'mongo.adapter' => function (array $a, Session $s) {
        Core::enter($s);
        $adapter = Core::db($a, $s)->getAdapter();
        if (!$adapter instanceof Mongo) {
            throw new Fault('mongo.adapter: the database is not on MongoDB');
        }

        $result = Core::invoke($adapter, (string) ($a['method'] ?? ''), (array) ($a['args'] ?? []), $s);

        // getDriver(): the client, as a marker.
        return $result instanceof \Utopia\Mongo\Client ? ['$client' => true] : Core::result($result);
    },
    // Mongo::permissionStrings($type) (private, by reflection, as
    // MongoPermissionStringsTest calls it) on an adapter whose
    // Authorization holds `roles`.
    'mongo.permission_strings' => function (array $a, Session $s) {
        $authorization = new Authorization();
        $authorization->enable();
        $authorization->cleanRoles();
        foreach ((array) ($a['roles'] ?? []) as $role) {
            $authorization->addRole((string) $role);
        }
        $adapter = (new \ReflectionClass(Mongo::class))->newInstanceWithoutConstructor();
        $adapter->setAuthorization($authorization);

        return (new \ReflectionMethod(Mongo::class, 'permissionStrings'))->invoke($adapter, (string) ($a['type'] ?? ''));
    },
];
