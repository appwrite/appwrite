<?php

declare(strict_types=1);

namespace Tests\E2E\Services\TablesDB\Transactions;

use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Databases\Transactions\ACIDBase;
use Tests\E2E\Traits\DatabasesUrlHelpers;

final class TablesDBACIDTest extends Scope
{
    use ACIDBase;
    use DatabasesUrlHelpers;
    use ProjectCustom;
    use SideClient;
    use ApiTablesDB;
}
