<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

use WebSocket\Client as WebSocketClient;
use WebSocket\ConnectionException;

class RealtimeCustomClientTest extends Scope
{
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;
}