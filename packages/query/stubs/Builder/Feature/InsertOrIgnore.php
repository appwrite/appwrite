<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\Statement;
interface InsertOrIgnore
{
    public function insertOrIgnore(): Statement;
}
