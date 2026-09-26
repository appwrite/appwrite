<?php

declare(strict_types=1);

namespace Utopia\Domains\Registrar;

final class UpdateDetails
{
    /**
     * @param bool|null $autoRenew Enable or disable automatic renewal
     */
    public function __construct(
        public ?bool $autoRenew = null,
    ) {}
}
