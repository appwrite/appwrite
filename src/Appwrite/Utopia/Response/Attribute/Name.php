<?php

namespace Appwrite\Utopia\Response\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Name
{
    public function __construct(
        public string $name,
    ) {
    }
}
