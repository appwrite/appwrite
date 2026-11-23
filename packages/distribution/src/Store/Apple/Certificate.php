<?php

declare(strict_types=1);

namespace Utopia\Distribution\Store\Apple;

use Utopia\Distribution\Signing;

final readonly class Certificate
{
    public const string TYPE_DISTRIBUTION = 'DISTRIBUTION';

    public const string TYPE_DEVELOPMENT = 'DEVELOPMENT';

    public const string TYPE_IOS_DISTRIBUTION = 'IOS_DISTRIBUTION';

    public const string TYPE_IOS_DEVELOPMENT = 'IOS_DEVELOPMENT';

    public function __construct(
        public string $id,
        public string $type,
        public string $serial,
        public \DateTimeImmutable $expiresAt,
        public Signing\Certificate $certificate,
    ) {
    }
}
