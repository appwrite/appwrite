<?php

namespace Utopia\Query\Hook;

use Utopia\Query\Hook;

interface Attribute extends Hook
{
    public function resolve(string $attribute): string;
}
