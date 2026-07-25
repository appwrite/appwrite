<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class LocaleConsoleClientTest extends Scope
{
    use LocaleBase;
    use ProjectConsole;
    use SideClient;
}
