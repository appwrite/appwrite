<?php

declare(strict_types=1);

namespace Tests\Unit\Presences\Mock;

use Appwrite\Event\Realtime;

/**
 * Realtime queue whose first trigger fails, then records the rest.
 */
final class FailingRealtime extends Realtime
{
    /**
     * @var list<mixed>
     */
    public array $triggered = [];

    private bool $failed = false;

    #[\Override]
    public function trigger(): string|bool
    {
        if (!$this->failed) {
            $this->failed = true;
            throw new \RuntimeException('Publish failed');
        }

        $this->triggered[] = $this->getParam('presenceId');

        return true;
    }
}
