<?php

declare(strict_types=1);

namespace Utopia\Domains\Registrar;

use DateTime;

final readonly class TransferStatus
{
    public function __construct(
        public TransferStatusEnum $status,
        public ?string $reason = null,
        public ?DateTime $timestamp = null,
    ) {}
}
