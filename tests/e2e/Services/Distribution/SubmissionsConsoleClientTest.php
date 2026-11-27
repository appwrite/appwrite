<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class SubmissionsConsoleClientTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use SubmissionsBase;
    use ProjectCustom;
    use SideConsole;

    private const int REFUSED = 8;
}
