<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Proxy;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class ProxyConsoleClientTest extends Scope
{
    use ProxyBase;
    use ProjectCustom;
    use SideConsole;
}
