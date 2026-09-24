<?php

namespace Utopia\Query\Builder\Feature;

interface BitwiseAggregates
{
    public function bitAnd(string $attribute, string $alias = ''): static;

    public function bitOr(string $attribute, string $alias = ''): static;

    public function bitXor(string $attribute, string $alias = ''): static;
}
