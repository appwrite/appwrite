<?php

declare(strict_types=1);

namespace Utopia\Distribution\Store\Apple;

final readonly class Webhook
{
    public const string NAME = 'Appwrite';

    public const string EVENT_VERSION_STATE = 'APP_STORE_VERSION_APP_VERSION_STATE_UPDATED';

    public const string EVENT_UPLOAD_STATE = 'BUILD_UPLOAD_STATE_UPDATED';

    public const string EVENT_BETA_STATE = 'BUILD_BETA_DETAIL_EXTERNAL_BUILD_STATE_UPDATED';

    public const array EVENTS = [
        self::EVENT_VERSION_STATE,
        self::EVENT_UPLOAD_STATE,
        self::EVENT_BETA_STATE,
    ];

    public const string HEADER = 'x-apple-signature';

    public const string PREFIX = 'hmacsha256=';

    private const string ALGORITHM = 'sha256';

    /**
     * @param list<string> $events
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public array $events,
        public bool $enabled,
    ) {
    }

    public static function sign(string $body, #[\SensitiveParameter] string $secret): string
    {
        return self::PREFIX . hash_hmac(self::ALGORITHM, $body, $secret);
    }

    public static function verify(string $body, #[\SensitiveParameter] string $secret, string $signature): bool
    {
        if ($secret === '') {
            return false;
        }

        return hash_equals(self::sign($body, $secret), $signature);
    }
}
