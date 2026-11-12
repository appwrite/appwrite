<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class PasswordsDeleteConsoleClientTest extends Scope
{
    use PasswordsDeleteBase;
    use ProjectCustom;
    use SideConsole;
}
