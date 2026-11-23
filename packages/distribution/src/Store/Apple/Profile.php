<?php

declare(strict_types=1);

namespace Utopia\Distribution\Store\Apple;

final readonly class Profile
{
    public const string TYPE_IOS_APP_STORE = 'IOS_APP_STORE';

    public const string TYPE_IOS_APP_DEVELOPMENT = 'IOS_APP_DEVELOPMENT';

    public const string TYPE_IOS_APP_ADHOC = 'IOS_APP_ADHOC';

    public const string TYPE_IOS_APP_INHOUSE = 'IOS_APP_INHOUSE';

    public const string STATE_ACTIVE = 'ACTIVE';

    public const string STATE_INVALID = 'INVALID';

    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $state,
        public string $uuid,
        public \DateTimeImmutable $expiresAt,
        public string $content,
    ) {
    }
}
