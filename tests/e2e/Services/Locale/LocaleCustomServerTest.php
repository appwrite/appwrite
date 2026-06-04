<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class LocaleCustomServerTest extends Scope
{
    use LocaleBase;
    use ProjectCustom;
    use SideServer;
}
