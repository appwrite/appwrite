<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\Statement;

interface Updates
{
    public function from(string $table): static;

    /**
     * @param  array<string, mixed>  $row
     */
    public function set(array $row): static;

    public function update(): Statement;
}
