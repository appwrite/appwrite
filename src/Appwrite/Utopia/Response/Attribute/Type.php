<?php

namespace Appwrite\Utopia\Response\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Type
{
    public function __construct(
        public string $type,
    ) {
    }
}
