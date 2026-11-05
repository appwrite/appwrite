<?php

declare(strict_types=1);

namespace Utopia\Queue;

use Utopia\Servers\Hook;

class Job extends Hook
{
    /**
     * Whether to use hook
     */
    protected bool $hook = true;

    /**
     * Set hook status
     * When set false, hooks for this route will be skipped.
     *
     *
     */
    public function hook(bool $hook = true): static
    {
        $this->hook = $hook;

        return $this;
    }

    /**
     * Get hook status
     */
    public function getHook(): bool
    {
        return $this->hook;
    }
}
