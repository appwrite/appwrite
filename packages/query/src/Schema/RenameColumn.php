<?php

namespace Utopia\Query\Schema;

readonly class RenameColumn
{
    public function __construct(
        public string $from,
        public string $to,
    ) {
    }
}
