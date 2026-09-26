<?php

declare(strict_types=1);

namespace Utopia\Domains\Registrar;

use DateTime;

final readonly class Renewal
{
    public function __construct(
        public ?string $orderId = null,
        public ?DateTime $expiresAt = null,
    ) {}
}
