<?php

namespace Utopia\Query\Builder\Feature;

interface Hints
{
    public function hint(string $hint): static;
}
