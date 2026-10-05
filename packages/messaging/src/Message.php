<?php

declare(strict_types=1);

namespace Utopia\Messaging;

/**
 * Marker interface for specific message types.
 */
interface Message
{
    public function setOrigin(?string $origin): self;

    public function getOrigin(): ?string;
}
