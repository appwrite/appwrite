<?php

use Utopia\System\System;

// ruleid: php.appwrite.weak-secret-env-default
$password = System::getEnv('_APP_USAGE_PASS', 'appwrite');

// ruleid: php.appwrite.weak-secret-env-default
$secret = System::getEnv('_APP_EXECUTOR_SECRET', 'secret');

// ruleid: php.appwrite.weak-secret-env-default
$token = getenv('_APP_GITHUB_TOKEN') ?: 'ghp_default';

// ok: php.appwrite.weak-secret-env-default
$secret = System::getEnv('_APP_EXECUTOR_SECRET', '');

// ok: php.appwrite.weak-secret-env-default
$dsn = System::getEnv('_APP_PWNED_PASSWORDS_DSN', 'none://localhost');

// ok: php.appwrite.weak-secret-env-default
$region = System::getEnv('_APP_REGION', 'default');
