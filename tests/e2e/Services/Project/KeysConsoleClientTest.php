<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class KeysConsoleClientTest extends Scope
{
    use KeysBase;
    use ProjectCustom;
    use SideConsole;
}
