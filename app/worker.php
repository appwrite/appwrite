<?php

require_once __DIR__ . '/init.php';

use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;

global $register;

Server::setResource('register', fn() => $register);

Server::setResource('dbForConsole', function (Cache $cache, Registry $register) {

    $pools = $register->get('pools');
    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, $cache);
    $database->setNamespace('console');

    return $database;
}, ['cache', 'register']);

Server::setResource('cache', function (Registry $register) {

    $pools = $register->get('pools');
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}, ['register']);

/**
 * Get console database
 * @return Database
 */
function getConsoleDB(): Database
{
    global $register;

    $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

    $dbAdapter = $pools
       ->get('console')
       ->pop()
       ->getResource()
    ;

    $database = new Database($dbAdapter, getCache());

    $database->setNamespace('console');

    return $database;
}

/**
 * Get Cache
 * @return Cache
 */
function getCache(): Cache
{
    global $register;

    $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}
