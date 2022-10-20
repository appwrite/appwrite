<?php

/**
 * Init
 *
 * Inializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources, app constants
 *
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

use Utopia\Preloader\Preloader;

include __DIR__ . '/controllers/general.php';

$preloader = new Preloader();

foreach (
    [
    realpath(__DIR__ . '/../vendor/composer'),
    realpath(__DIR__ . '/../vendor/amphp'),
    realpath(__DIR__ . '/../vendor/felixfbecker'),
    realpath(__DIR__ . '/../vendor/twig/twig'),
    realpath(__DIR__ . '/../vendor/guzzlehttp/guzzle'),
    realpath(__DIR__ . '/../vendor/slickdeals'),
    realpath(__DIR__ . '/../vendor/psr/log'),
    realpath(__DIR__ . '/../vendor/matomo'),
    realpath(__DIR__ . '/../vendor/symfony'),
    realpath(__DIR__ . '/../vendor/mongodb'),
    realpath(__DIR__ . '/../vendor/utopia-php/websocket'), // TODO: remove workerman autoload
    realpath(__DIR__ . '/../vendor/utopia-php/cache'), // TODO: Remove when memcached ext issue get fixed
    realpath(__DIR__ . '/../vendor/utopia-php/queue'), // TODO: Remove when memcached ext issue get fixed
    ] as $key => $value
) {
    if ($value !== false) {
        $preloader->ignore($value);
    }
}

$preloader
    ->paths(realpath(__DIR__ . '/../app/config'))
    ->paths(realpath(__DIR__ . '/../app/controllers'))
    ->paths(realpath(__DIR__ . '/../src'))
    ->load();
