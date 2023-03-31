<?php

namespace Appwrite\Utopia\Response\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Options
{
    public function __construct(
        public bool $none = false,
        public bool $any = false,
        public bool $public = true
    ) {
    }
}
