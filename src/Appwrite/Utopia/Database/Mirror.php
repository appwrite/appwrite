<?php

namespace Appwrite\Utopia\Database;

use Utopia\Database\Mirror as UtopiaMirror;

class Mirror extends UtopiaMirror
{
    public function __construct(
        Database $source,
        ?Database $destination = null,
        array $filters = [],
    ) {
        parent::__construct($source, $destination, $filters);
    }
}
