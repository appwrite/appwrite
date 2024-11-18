<?php

namespace Tests\E2E\Services\Tokens;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class TokensConsoleClientTest extends Scope
{
    use SideConsole;
    use TokensBase;
    use ProjectCustom;
}
