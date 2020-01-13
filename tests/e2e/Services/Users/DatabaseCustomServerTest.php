<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class DatabaseCustomServerTest extends Scope
{
    use DatabaseBase;
    use ProjectCustom;
    use SideServer;
}