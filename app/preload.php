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
    require_once __DIR__.'/../vendor/autoload.php';
}

use Appwrite\Preloader\Preloader;

require_once __DIR__.'/../app/init.php';
require_once __DIR__.'/../app/app.php';

(new Preloader())
    ->paths(realpath(__DIR__ . '/../vendor'))
    ->paths(realpath(__DIR__ . '/../app/config'))
    ->paths(realpath(__DIR__ . '/../app/controllers'))
    ->paths(realpath(__DIR__ . '/../app/views'))
    ->ignore(__DIR__ . '/../vendor/phpmailer/phpmailer/get_oauth_token.php')
    ->load();