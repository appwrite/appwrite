<?php

use Utopia\Config\Config;

$common = include __DIR__ . '/collections/common.php';
$bucket = include __DIR__ . '/collections/bucket.php';
$project = include __DIR__ . '/collections/project.php';
$database = include __DIR__ . '/collections/database.php';
$platform = include __DIR__ . '/collections/platform.php';

$auth = Config::getParam('auth', []);
$providers = Config::getParam('oAuthProviders', []);

/**
 * $collection => id of the parent collection where this will be inserted
 * $id => id of this collection
 * name => name of this collection
 * project => whether this collection should be created per project
 * attributes => list of attributes
 * indexes => list of indexes
 */

$collections = [
    'buckets' => $bucket,
    'databases' => $database,
    'projects' => array_merge($project, $common),
    'console' => array_merge($platform, $common),
];

return $collections;
