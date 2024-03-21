<?php

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class LocaleCustomServerTest extends Scope
{
    use LocaleBase;
    use ProjectCustom;
    use SideServer;
}
