<?php

namespace Tests\E2E\Services\Avatars;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class AvatarsCustomServerTest extends Scope
{
    use AvatarsBase;
    use ProjectCustom;
    use SideServer;
}
