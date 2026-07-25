<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class LocaleCustomClientTest extends Scope
{
    use LocaleBase;
    use ProjectCustom;
    use SideClient;
}
