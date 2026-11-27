<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface BitwiseAggregates
{
    public function bitAnd(string $attribute, string $alias = ''): static;
    public function bitOr(string $attribute, string $alias = ''): static;
    public function bitXor(string $attribute, string $alias = ''): static;
}
