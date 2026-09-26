<?php

namespace Utopia\Query\Builder\Trait\MariaDB;

use Utopia\Query\Exception\ValidationException;

trait Sequences
{
    #[\Override]
    public function nextVal(string $sequence, string $alias = ''): static
    {
        return $this->emitSequenceCall('NEXTVAL', $sequence, $alias);
    }

    /**
     * MariaDB exposes the session-local last value via LASTVAL(seq), not
     * CURRVAL(seq). The feature interface uses currVal() for dialect-neutral
     * callers and is compiled to LASTVAL() here.
     */
    #[\Override]
    public function currVal(string $sequence, string $alias = ''): static
    {
        return $this->emitSequenceCall('LASTVAL', $sequence, $alias);
    }

    private function emitSequenceCall(string $function, string $sequence, string $alias): static
    {
        if (! \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $sequence)) {
            throw new ValidationException('Invalid sequence name: ' . $sequence);
        }

        $expression = $function . '(' . $this->quote($sequence) . ')';

        if ($alias !== '') {
            if (! \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                throw new ValidationException('Invalid sequence alias: ' . $alias);
            }
            $expression .= ' AS ' . $this->quote($alias);
        }

        return $this->selectRaw($expression);
    }
}
