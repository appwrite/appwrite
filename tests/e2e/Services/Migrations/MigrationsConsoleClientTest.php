<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Migrations;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class MigrationsConsoleClientTest extends Scope
{
    use MigrationsBase;
    use SideConsole;
}
