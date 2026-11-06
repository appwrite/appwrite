<?php

namespace Utopia\Query\Builder;

readonly class WindowFrame
{
    public function __construct(
        public string $type,
        public string $start,
        public ?string $end = null,
    ) {
    }

    public function toSql(): string
    {
        if ($this->end === null) {
            return $this->type . ' ' . $this->start;
        }

        return $this->type . ' BETWEEN ' . $this->start . ' AND ' . $this->end;
    }
}
