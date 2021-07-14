<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;


class RealtimeCustomClientTest extends Scope
{
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;
}