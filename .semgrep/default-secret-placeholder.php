<?php

use Utopia\System\System;

// ruleid: php.appwrite.default-secret-placeholder
$secret = System::getEnv('_APP_OPENSSL_KEY_V1', 'your-secret-key');

// ruleid: php.appwrite.default-secret-placeholder
$fallback = 'your-secret-key';

// ruleid: php.appwrite.default-secret-placeholder
$key = System::getEnv('_APP_EXECUTOR_SECRET', "changeme");

// ruleid: php.appwrite.default-secret-placeholder
$token = 'your-api-key';

// ruleid: php.appwrite.default-secret-placeholder
$jwt = 'insecure-secret';

// ok: php.appwrite.default-secret-placeholder
$secret = System::getEnv('_APP_OPENSSL_KEY_V1');

// ok: php.appwrite.default-secret-placeholder
$label = 'Change me later';

// ok: php.appwrite.default-secret-placeholder
$attribute = 'secret';
