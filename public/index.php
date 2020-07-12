<?php

/**
 * “Programming today is a race between software engineers striving to build bigger and better idiot-proof programs,
 * and the Universe trying to produce bigger and better idiots.
 * So far, the Universe is winning.”
 *
 * ― Rick Cook, The Wizardry Compiled
 */

use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Request;

error_reporting(0);
ini_set('display_errors', 0);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
//trigger_error('hide errors in prod', E_USER_NOTICE);

include __DIR__ . '/../app/app.php';

$app = new App('Asia/Tel_Aviv');

$app->run(new Request(), new Response());
