<?php

declare(strict_types=1);

namespace Utopia\Config\Attribute;

use Attribute;
use Utopia\Validator;

#[Attribute]
class Key
{
    public function __construct(
        public string $name,
        public Validator $validator,
        public bool $required = true,
    ) {}
}
