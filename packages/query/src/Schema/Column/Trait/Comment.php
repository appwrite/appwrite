<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Inline column comments. PostgreSQL cannot express one inside CREATE TABLE --
 * it needs a separate COMMENT ON statement -- so use
 * {@see \Utopia\Query\Schema\PostgreSQL::commentOnColumn()} there instead.
 */
trait Comment
{
    public function comment(string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }
}
