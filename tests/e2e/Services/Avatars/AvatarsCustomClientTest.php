<?php

namespace Tests\E2E\Services\Avatars;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class AvatarsCustomClientTest extends Scope
{
    use AvatarsBase;
    use ProjectCustom;
    use SideClient;
}
