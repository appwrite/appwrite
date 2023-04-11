<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class AccountConsoleClientTest extends Scope
{
    use AccountBase;
    use ProjectConsole;
    use SideClient;
}
