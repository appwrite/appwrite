<?php

use Utopia\Config\Config;

include_once __DIR__ . '/collections/common.php';
include_once __DIR__ . '/collections/bucket.php';
include_once __DIR__ . '/collections/project.php';
include_once __DIR__ . '/collections/database.php';
include_once __DIR__ . '/collections/platform.php';

$providers = Config::getParam('oAuthProviders', []);
$auth = Config::getParam('auth', []);

/**
 * $collection => id of the parent collection where this will be inserted
 * $id => id of this collection
 * name => name of this collection
 * project => whether this collection should be created per project
 * attributes => list of attributes
 * indexes => list of indexes
 */

$commonCollections = getCommonCollections();

$collections = [
    'buckets' => getBucketCollections(),
    'databases' => getDatabaseCollections(),
    'projects' => getProjectCollections($commonCollections),
    'console' => getPlatformCollections($commonCollections),
];

return $collections;
