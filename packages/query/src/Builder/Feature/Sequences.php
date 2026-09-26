<?php

namespace Utopia\Query\Builder\Feature;

interface Sequences
{
    /**
     * Emit NEXTVAL(<sequence>) as a select expression — advances the named
     * sequence and returns the next value.
     */
    public function nextVal(string $sequence, string $alias = ''): static;

    /**
     * Emit CURRVAL(<sequence>) as a select expression — returns the current
     * (session-local) value of the named sequence.
     */
    public function currVal(string $sequence, string $alias = ''): static;
}
