<?php

namespace Utopia\Agents\Parts;

use Utopia\Agents\Part;

final readonly class Text implements Part
{
    public function __construct(
        public string $text,
    ) {
    }
}
