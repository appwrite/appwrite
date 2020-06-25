<?php

/**
 * “Programming today is a race between software engineers striving to build bigger and better idiot-proof programs,
 * and the Universe trying to produce bigger and better idiots.
 * So far, the Universe is winning.”
 *
 * ― Rick Cook, The Wizardry Compiled
 */

error_reporting(0);
ini_set('display_errors', 0);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$path = (isset($_GET['q'])) ? explode('/', $_GET['q']) : [];

array_shift($path);
$version = array_shift($path);

include __DIR__ . '/../app/app.php';
