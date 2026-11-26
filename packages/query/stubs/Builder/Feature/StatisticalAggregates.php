<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface StatisticalAggregates
{
    public function stddev(string $attribute, string $alias = ''): static;
    public function stddevPop(string $attribute, string $alias = ''): static;
    public function stddevSamp(string $attribute, string $alias = ''): static;
    public function variance(string $attribute, string $alias = ''): static;
    public function varPop(string $attribute, string $alias = ''): static;
    public function varSamp(string $attribute, string $alias = ''): static;
}
