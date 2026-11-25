<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Updates
{
    public function from(string $table): static;
    /**
     * @param  array<string, mixed>  $row
     */
    public function set(array $row): static;
    public function update(): \Utopia\Query\Builder\Statement;
}
