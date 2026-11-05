<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder\WindowDefinition;
use Utopia\Query\Builder\WindowFrame;
use Utopia\Query\Builder\WindowSelect;
use Utopia\Query\Exception\ValidationException;

trait Windows
{
    #[\Override]
    public function selectWindow(string $function, string $alias, ?array $partitionBy = null, ?array $orderBy = null, ?string $windowName = null, ?WindowFrame $frame = null): static
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*\(\s*[A-Za-z0-9_,.\s*"`]*\s*\)$/', \trim($function))) {
            throw new ValidationException('Invalid window function: ' . $function);
        }

        $this->windowSelects[] = new WindowSelect($function, $alias, $partitionBy, $orderBy, $windowName, $frame);

        return $this;
    }

    #[\Override]
    public function window(string $name, ?array $partitionBy = null, ?array $orderBy = null, ?WindowFrame $frame = null): static
    {
        $this->windowDefinitions[] = new WindowDefinition($name, $partitionBy, $orderBy, $frame);

        return $this;
    }
}
