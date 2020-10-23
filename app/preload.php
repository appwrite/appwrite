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

if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
}

use Utopia\Preloader\Preloader;

include __DIR__.'/controllers/general.php';

$preloader = new Preloader();

foreach ([
    realpath(__DIR__ . '/../vendor/composer'),
    realpath(__DIR__ . '/../vendor/twig/twig'),
    realpath(__DIR__ . '/../vendor/guzzlehttp/guzzle'),
    realpath(__DIR__ . '/../vendor/domnikl'),
    realpath(__DIR__ . '/../vendor/geoip2'),
    realpath(__DIR__ . '/../vendor/domnikl'),
    realpath(__DIR__ . '/../vendor/maxmind'),
    realpath(__DIR__ . '/../vendor/maxmind-db'),
    realpath(__DIR__ . '/../vendor/psr/log'),
    realpath(__DIR__ . '/../vendor/piwik'),
    realpath(__DIR__ . '/../vendor/symfony'),
] as $key => $value) {
    if($value !== false) {
        $preloader->ignore($value);
    }
}

$preloader
    ->paths(realpath(__DIR__ . '/../app/config'))
    ->paths(realpath(__DIR__ . '/../app/controllers'))
    ->paths(realpath(__DIR__ . '/../src'))
    ->load();