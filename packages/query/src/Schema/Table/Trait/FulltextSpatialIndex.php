<?php

namespace Utopia\Query\Schema\Table\Trait;

use Utopia\Query\Schema\Index;
use Utopia\Query\Schema\IndexType;

trait FulltextSpatialIndex
{
    /**
     * @param  string[]  $columns
     */
    public function fulltextIndex(array $columns, string $name = ''): static
    {
        if ($name === '') {
            $name = $this->autoIndexName('ft_', $columns);
        }
        $this->indexes[] = new Index($name, $columns, IndexType::Fulltext);

        return $this;
    }

    /**
     * @param  string[]  $columns
     */
    public function spatialIndex(array $columns, string $name = ''): static
    {
        if ($name === '') {
            $name = $this->autoIndexName('sp_', $columns);
        }
        $this->indexes[] = new Index($name, $columns, IndexType::Spatial);

        return $this;
    }
}
