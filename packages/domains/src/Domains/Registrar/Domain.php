<?php

declare(strict_types=1);

namespace Utopia\Domains\Registrar;

use DateTime;

final readonly class Domain
{
    public function __construct(
        public string $domain,
        public ?DateTime $createdAt = null,
        public ?DateTime $expiresAt = null,
        public ?bool $autoRenew = null,
        public ?array $nameservers = null,
    ) {}
}
