<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class LegacyCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;
    use ApiLegacy;
}
