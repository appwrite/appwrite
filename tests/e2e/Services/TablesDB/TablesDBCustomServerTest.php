<?php

declare(strict_types=1);

namespace Tests\E2E\Services\TablesDB;

use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Databases\DatabasesBase;

final class TablesDBCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;
    use ApiTablesDB;
}
