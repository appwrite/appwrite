<?php

namespace Utopia\Query\Builder\Trait\PostgreSQL;

use Utopia\Query\Exception\ValidationException;

trait Sequences
{
    #[\Override]
    public function nextVal(string $sequence, string $alias = ''): static
    {
        return $this->emitSequenceCall('nextval', $sequence, $alias);
    }

    #[\Override]
    public function currVal(string $sequence, string $alias = ''): static
    {
        return $this->emitSequenceCall('currval', $sequence, $alias);
    }

    private function emitSequenceCall(string $function, string $sequence, string $alias): static
    {
        if (! \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $sequence)) {
            throw new ValidationException('Invalid sequence name: ' . $sequence);
        }

        $expression = $function . "('" . $sequence . "')";

        if ($alias !== '') {
            if (! \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                throw new ValidationException('Invalid sequence alias: ' . $alias);
            }
            $expression .= ' AS ' . $this->quote($alias);
        }

        return $this->selectRaw($expression);
    }
}
