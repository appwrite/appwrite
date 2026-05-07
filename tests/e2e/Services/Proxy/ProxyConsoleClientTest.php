<?php

namespace Tests\E2E\Services\Proxy;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class ProxyConsoleClientTest extends Scope
{
    use ProxyBase;
    use ProjectCustom;
    use SideConsole;
}
