<?php

declare(strict_types=1);

namespace Tests\E2E\Services\TablesDB;

use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Databases\TrigramIndex;

final class TablesDBTrigramIndexServerTest extends Scope
{
    use ApiTablesDB;
    use ProjectCustom;
    use SideServer;
    use TrigramIndex;
}
