<?php

/**
 * Init
 * 
 * Inializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources, app constants
 * 
 */

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
}

use Appwrite\Preloader\Preloader;

(new Preloader())
    ->paths(realpath(__DIR__ . '/../app/config'))
    ->paths(realpath(__DIR__ . '/../src'))
    ->ignore(realpath(__DIR__ . '/../vendor/twig/twig'))
    ->ignore(realpath(__DIR__ . '/../vendor/guzzlehttp/guzzle'))
    ->ignore(realpath(__DIR__ . '/../vendor/geoip2/geoip2'))
    ->load();