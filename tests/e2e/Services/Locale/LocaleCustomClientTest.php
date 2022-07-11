<?php

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class LocaleCustomClientTest extends Scope
{
    use LocaleBase;
    use ProjectCustom;
    use SideClient;
}
