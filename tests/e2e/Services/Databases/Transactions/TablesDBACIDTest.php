<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Traits\DatabasesUrlHelpers;

class TablesDBACIDTest extends Scope
{
    use ACIDBase;
    use DatabasesUrlHelpers;
    use ProjectCustom;
    use SideClient;
    use ApiTablesDB;
}
