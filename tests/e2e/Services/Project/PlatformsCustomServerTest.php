<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class PlatformsCustomServerTest extends Scope
{
    use PlatformsBase;
    use ProjectCustom;
    use SideServer;
}
