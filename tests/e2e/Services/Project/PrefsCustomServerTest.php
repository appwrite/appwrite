<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class PrefsCustomServerTest extends Scope
{
    use PrefsBase;
    use ProjectCustom;
    use SideServer;
}
