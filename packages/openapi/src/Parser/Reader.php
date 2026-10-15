<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Parser;

use Utopia\OpenAPI\Specification;

interface Reader
{
    public function read(): Specification;
}
