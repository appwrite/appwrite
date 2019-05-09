<?php

/**
 * “Programming today is a race between software engineers striving to build bigger and better idiot-proof programs,
 * and the Universe trying to produce bigger and better idiots.
 * So far, the Universe is winning.”
 *
 * ― Rick Cook, The Wizardry Compiled
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/*
error_reporting(0);
ini_set('display_errors', 0);*/

$path = (isset($_GET['q'])) ? explode('/', $_GET['q']) : [];
$domain = (isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : '';

array_shift($path);
$version = array_shift($path);

switch ($version) { // Switch between API version
    case 'v1':
        $service = $version . '/' . array_shift($path);
        include '../app/app.php';
        break;
    case 'console':
    default:
        $service = $version . '/';
        include '../app/app.php';
        break;
}