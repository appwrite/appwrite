<?php

declare(strict_types=1);

namespace Utopia\Distribution\Store\Apple;

final readonly class Bundle
{
    public const string PLATFORM_IOS = 'IOS';

    public const string PLATFORM_MAC_OS = 'MAC_OS';

    public const string PLATFORM_UNIVERSAL = 'UNIVERSAL';

    public const string CAPABILITY_APP_GROUPS = 'APP_GROUPS';

    public const string CAPABILITY_APPLE_ID_AUTH = 'APPLE_ID_AUTH';

    public const string CAPABILITY_APPLE_PAY = 'APPLE_PAY';

    public const string CAPABILITY_ASSOCIATED_DOMAINS = 'ASSOCIATED_DOMAINS';

    public const string CAPABILITY_GAME_CENTER = 'GAME_CENTER';

    public const string CAPABILITY_HEALTHKIT = 'HEALTHKIT';

    public const string CAPABILITY_ICLOUD = 'ICLOUD';

    public const string CAPABILITY_IN_APP_PURCHASE = 'IN_APP_PURCHASE';

    public const string CAPABILITY_MAPS = 'MAPS';

    public const string CAPABILITY_NFC_TAG_READING = 'NFC_TAG_READING';

    public const string CAPABILITY_PUSH_NOTIFICATIONS = 'PUSH_NOTIFICATIONS';

    public const string CAPABILITY_WALLET = 'WALLET';

    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        public string $id,
        public string $identifier,
        public string $name,
        public string $platform,
        public string $seed,
        public array $capabilities,
    ) {
    }
}
