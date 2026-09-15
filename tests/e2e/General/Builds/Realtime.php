<?php

declare(strict_types=1);

namespace Tests\E2E\General\Builds;

final class Realtime extends \Appwrite\Event\Realtime
{
    public array $payloads = [];

    public function trigger(): string|bool
    {
        $this->payloads[] = $this->getPayload();

        return true;
    }
}
