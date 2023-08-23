<?php

namespace Tests\E2E\Services\Avatars;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideClient;

class AvatarsConsoleClientTest extends Scope
{
    use AvatarsBase;
    use ProjectConsole;
    use SideClient;
}
