<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class VariablesCustomServerTest extends Scope
{
    use DistributionBase;
    use VariablesBase;
    use ProjectCustom;
    use SideServer;
}
