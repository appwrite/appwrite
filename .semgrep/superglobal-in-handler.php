<?php

// ruleid: php.appwrite.superglobal-in-handler
$id = $_GET['id'];

// ruleid: php.appwrite.superglobal-in-handler
$body = $_POST;

// ruleid: php.appwrite.superglobal-in-handler
$session = $_COOKIE['a_session'] ?? '';

// ruleid: php.appwrite.superglobal-in-handler
$ip = $_SERVER['REMOTE_ADDR'];

// ruleid: php.appwrite.superglobal-in-handler
$key = $_ENV['_APP_OPENSSL_KEY_V1'];

// ok: php.appwrite.superglobal-in-handler
$id = $request->getParam('id');

// ok: php.appwrite.superglobal-in-handler
$key = System::getEnv('_APP_OPENSSL_KEY_V1');
