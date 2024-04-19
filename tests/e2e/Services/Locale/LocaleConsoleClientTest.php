<?php

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class LocaleConsoleClientTest extends Scope
{
    use LocaleBase;
    use ProjectConsole;
    use SideClient;
}
