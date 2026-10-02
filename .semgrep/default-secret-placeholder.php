<?php

use Utopia\System\System;

// ruleid: php.appwrite.default-secret-placeholder
$secret = System::getEnv('_APP_OPENSSL_KEY_V1', 'your-secret-key');

// ruleid: php.appwrite.default-secret-placeholder
$fallback = 'your-secret-key';

// ok: php.appwrite.default-secret-placeholder
$secret = System::getEnv('_APP_OPENSSL_KEY_V1');
