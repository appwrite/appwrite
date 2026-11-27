<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class ChecksConsoleClientTest extends Scope
{
    use ChecksBase;
    use DistributionBase;
    use ProjectCustom;
    use SideConsole;
}
