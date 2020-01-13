<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideClient;

class DatabaseConsoleClientTest extends Scope
{
    use DatabaseBase;
    use ProjectConsole;
    use SideClient;
}