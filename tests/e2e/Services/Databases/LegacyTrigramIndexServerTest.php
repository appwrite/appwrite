<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class LegacyTrigramIndexServerTest extends Scope
{
    use ApiLegacy;
    use ProjectCustom;
    use SideServer;
    use TrigramIndex;
}
