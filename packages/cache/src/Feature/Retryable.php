<?php

declare(strict_types=1);

namespace Utopia\Cache\Feature;

interface Retryable
{
    public const MIN_RETRIES = 0;

    public const MAX_RETRIES = 10;

    /**
     * @param  int  $maxRetries (0-10)
     */
    public function setMaxRetries(int $maxRetries): self;

    /**
     * @param  int  $retryDelay time in milliseconds
     */
    public function setRetryDelay(int $retryDelay): self;

    public function getMaxRetries(): int;

    public function getRetryDelay(): int;
}
