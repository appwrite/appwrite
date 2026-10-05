<?php

namespace Utopia\Query\Builder\Feature;

interface FullTextSearch
{
    public function filterSearch(string $attribute, string $value): static;
}
