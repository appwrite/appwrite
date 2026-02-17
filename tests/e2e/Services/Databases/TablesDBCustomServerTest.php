<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class TablesDBCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;
    use ApiTablesDB;
}
