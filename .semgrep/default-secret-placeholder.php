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

// ruleid: php.appwrite.default-secret-placeholder
$key = System::getEnv('_APP_OPENSSL_KEY_V1', EncryptionKey::PLACEHOLDER);

// ok: php.appwrite.default-secret-placeholder
$secret = System::getEnv('_APP_OPENSSL_KEY_V1');

// ok: php.appwrite.default-secret-placeholder
$label = 'Change me later';

// ok: php.appwrite.default-secret-placeholder
$attribute = 'secret';

// ok: php.appwrite.default-secret-placeholder
if ($secret !== 'changeme') {
}

// ok: php.appwrite.default-secret-placeholder
throw new \RuntimeException('Replace "changeme" with a unique secret.');

final class EncryptionKey
{
    // ok: php.appwrite.default-secret-placeholder
    public const string PLACEHOLDER = 'your-secret-key';

    // ruleid: php.appwrite.default-secret-placeholder
    private const string FALLBACK = 'your-secret-key';

    public static function isInsecure(?string $key): bool
    {
        // ok: php.appwrite.default-secret-placeholder
        return $key === null || $key === '' || $key === self::PLACEHOLDER;
    }

    public static function assertProduction(?string $key): void
    {
        // ok: php.appwrite.default-secret-placeholder
        if ($key === self::PLACEHOLDER) {
            // ok: php.appwrite.default-secret-placeholder
            Console::warning('_APP_OPENSSL_KEY_V1 is the public default "' . self::PLACEHOLDER . '".');
        }
    }

    public static function fallback(): string
    {
        // ruleid: php.appwrite.default-secret-placeholder
        return System::getEnv('_APP_OPENSSL_KEY_V1', self::PLACEHOLDER);
    }
}
