<?php

namespace Tests\E2E\Services\Migrations;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class MigrationsConsoleClientTest extends Scope
{
    use MigrationsBase;
    use SideConsole;
}
