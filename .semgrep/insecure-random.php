<?php

// ruleid: php.appwrite.insecure-random
$code = rand(100000, 999999);

// ruleid: php.appwrite.insecure-random
$code = \mt_rand(100000, 999999);

// ruleid: php.appwrite.insecure-random
$secret = str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789');

// ruleid: php.appwrite.insecure-random
mt_srand(42);

// ruleid: php.appwrite.insecure-random
$token = md5(uniqid());

// ruleid: php.appwrite.insecure-random
$token = \md5(\uniqid('', true));

// ruleid: php.appwrite.insecure-random
$token = sha1(time() . $userId);

// ruleid: php.appwrite.insecure-random
$token = hash('sha256', $userId . microtime(true));

// ok: php.appwrite.insecure-random
$code = random_int(100000, 999999);

// ok: php.appwrite.insecure-random
$secret = bin2hex(random_bytes(32));

// ok: php.appwrite.insecure-random
$dsn = $databases[array_rand($databases)];

// ok: php.appwrite.insecure-random
$etag = md5($content);
