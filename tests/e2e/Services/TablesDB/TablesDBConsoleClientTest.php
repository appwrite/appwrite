<?php

namespace Tests\E2E\Services\TablesDB;

use Tests\E2E\Services\Databases\DatabasesBase;
use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class TablesDBConsoleClientTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideConsole;
    use ApiTablesDB;
}
