<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class OAuth2ConsoleClientTest extends Scope
{
    use OAuth2Base;
    use ProjectCustom;
    use SideConsole;
}
