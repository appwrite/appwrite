<?php

use Utopia\App;

$user = App::getEnv('_APP_REDIS_USER', '');
$pass = App::getEnv('_APP_REDIS_PASS', '');
if (!empty($user) || !empty($pass)) {
    Resque::setBackend('redis://' . $user . ':' . $pass . '@' . App::getEnv('_APP_REDIS_HOST', '') . ':' . App::getEnv('_APP_REDIS_PORT', ''));
} else {
    Resque::setBackend(App::getEnv('_APP_REDIS_HOST', '') . ':' . App::getEnv('_APP_REDIS_PORT', ''));
}
