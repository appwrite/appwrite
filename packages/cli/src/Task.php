<?php

declare(strict_types=1);

namespace Utopia\CLI;

use Utopia\Servers\Hook;

class Task extends Hook
{
    protected string $name = '';

    /**
     * Task constructor.
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Get Name
     */
    public function getName(): string
    {
        return $this->name;
    }
}
