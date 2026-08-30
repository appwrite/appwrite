<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Avatars;

use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class AvatarsConsoleClientTest extends Scope
{
    use AvatarsBase;
    use ProjectConsole;
    use SideClient;
}
