<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Exception\ValidationException;

trait Hints
{
    /** @var list<string> */
    protected array $hints = [];

    #[\Override]
    public function hint(string $hint): static
    {
        if (!\preg_match('/^[A-Za-z0-9_()=, `.]+$/', $hint)) {
            throw new ValidationException('Invalid hint: ' . $hint);
        }

        $this->hints[] = $hint;

        return $this;
    }
}
