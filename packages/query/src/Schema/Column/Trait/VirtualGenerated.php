<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Virtual (computed-on-read) generated columns. Separate from
 * {@see Generated} because PostgreSQL supports STORED only.
 */
trait VirtualGenerated
{
    public function virtual(): static
    {
        $this->generatedStored = false;

        return $this;
    }
}
