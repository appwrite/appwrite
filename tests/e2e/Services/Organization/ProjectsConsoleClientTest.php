<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Organization;

use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class ProjectsConsoleClientTest extends Scope
{
    use ProjectsBase;
    use ProjectConsole;
    use SideConsole;
}
